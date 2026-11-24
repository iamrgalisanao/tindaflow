<?php

namespace App\Services\License\Renewal;

use App\Services\Audit\AuditTrail;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\Revocation;
use App\Services\License\Contract\SignedEnvelope;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-018 decisions 1, 5 and 7: asks the control plane for the successor of the certificate held, and applies the answer only
 * if it passes every check. Nothing the control plane says changes the installation unless it is vendor-signed: an unsigned
 * "forked" or "revoked" is only reported. One renewal runs at a time (a database lock shared by the hourly timer and a manual
 * "check now"). The request id is saved BEFORE the request is sent and reused until an answer is applied, so a retry after a lost
 * response is the same request and the provider can return the stored successor instead of forking the chain.
 */
final class RenewalService
{
    /** A mark moved back by more than this when re-baselined is worth an audit note (it is the same tolerance as a clock rollback). */
    private const REBASELINE_NOTE_SECONDS = 3600;

    public function __construct(
        private readonly LicenseGate $gate,
        private readonly InstallationIdentity $identity,
        private readonly ControlPlane $controlPlane,
        private readonly TrustedClock $clock,
    ) {}

    public function renew(?DateTimeImmutable $now = null): RenewalResult
    {
        $now ??= CarbonImmutable::now();
        $ring = $this->gate->keyRing();
        if ($ring->isEmpty()) {
            return new RenewalResult(RenewalResult::SKIPPED, 'This build carries no vendor key, so there is nothing to renew.');
        }

        $row = DB::table('license_state')->where('id', 1)->first();
        if ($row === null || $row->certificate === null) {
            return new RenewalResult(RenewalResult::SKIPPED, 'This installation is not activated.');
        }
        if ($row->revoked) {
            return new RenewalResult(RenewalResult::SKIPPED, 'This installation has been revoked by the provider.');
        }

        $lock = Cache::lock('license-renewal', 300);
        if (! $lock->get()) {
            return new RenewalResult(RenewalResult::SKIPPED, 'A renewal is already running.');
        }

        try {
            return $this->attempt($row, $now);
        } finally {
            $lock->release();
        }
    }

    private function attempt(object $row, DateTimeImmutable $now): RenewalResult
    {
        $verifier = new CertificateVerifier($this->gate->keyRing());

        try {
            $current = $verifier->verify($row->certificate);
            $identity = $this->identity->load();
        } catch (CertificateRejected|RuntimeException $e) {
            return $this->failed('The certificate held cannot be used ('.$e->getMessage().').', $now);
        }

        if ($identity['installation'] !== $current->installation) {
            return $this->failed("This computer's identity file does not match the certificate held. Nothing was sent.", $now);
        }

        $urls = json_decode((string) $row->control_plane, true);
        if (! is_array($urls) || $urls === []) {
            return $this->failed('No control plane address is stored for this installation.', $now);
        }

        $requestId = $row->pending_request_id ?? (string) Str::uuid();
        DB::table('license_state')->where('id', 1)->update(['pending_request_id' => $requestId, 'last_renewal_attempt_at' => $this->stamp($now)]);

        $reply = $this->controlPlane->renew($urls, [
            'v' => 1,
            'request_id' => $requestId,
            'installation' => $current->installation,
            'certificate' => $row->certificate,
            'fingerprint' => null,
        ], $current->installation, $identity['secret_key']);

        if ($reply->status === 0) {
            return $this->failed('The control plane could not be reached ('.$reply->error.').', $now);
        }

        $status = $reply->json['status'] ?? null;

        if ($reply->status === 409) {
            return $this->failed('The provider reports a forked certificate chain. Nothing was changed; contact your TindaFlow provider.', $now);
        }

        if ($reply->status === 200 && $status === 'REVOKED') {
            return $this->applyRevocation($reply, $current, $now);
        }

        if ($reply->status === 200 && in_array($status, ['RENEWED', 'UNCHANGED'], true) && is_string($reply->json['certificate'] ?? null)) {
            return $this->applyCertificate($reply, $row->certificate, $current, $verifier, $status === 'UNCHANGED', $now);
        }

        return $this->failed("Unexpected answer from the control plane (HTTP {$reply->status}).", $now);
    }

    private function applyCertificate(ControlPlaneReply $reply, string $currentToken, Certificate $current, CertificateVerifier $verifier, bool $claimsUnchanged, DateTimeImmutable $now): RenewalResult
    {
        $token = (string) $reply->json['certificate'];

        try {
            $candidate = $verifier->verify($token);
        } catch (CertificateRejected $e) {
            return $this->failed('The certificate returned was refused ('.$e->reason.'). Nothing was changed.', $now);
        }

        if (($why = ChainRules::refusal($current, $candidate, $now)) !== null) {
            return $this->failed("The certificate returned was refused: {$why}. Nothing was changed.", $now);
        }

        if (ChainRules::isSame($current, $candidate)) {
            DB::table('license_state')->where('id', 1)->update(['pending_request_id' => null, 'last_renewal_error' => null, 'last_renewed_at' => $this->stamp($now), 'updated_at' => $this->stamp($now)]);

            return new RenewalResult(RenewalResult::UNCHANGED, 'The certificate is still current (valid until '.$current->exp->format('Y-m-d H:i').').');
        }

        if ($claimsUnchanged) {
            return $this->failed('The provider said the certificate was unchanged but sent a different one. Nothing was changed.', $now);
        }

        $linksCleanly = ChainRules::linksCleanly($currentToken, $current, $candidate);

        DB::transaction(function () use ($token, $current, $candidate, $linksCleanly, $reply, $now) {
            $previousMark = $this->clock->rebaseline($candidate->iat);

            DB::table('license_state')->where('id', 1)->update([
                'certificate' => $token,
                'certificate_seq' => $candidate->seq,
                'pending_request_id' => null,
                'last_renewal_error' => null,
                'last_renewed_at' => $this->stamp($now),
                'updated_at' => $this->stamp($now),
            ]);

            $movedBack = $previousMark !== null && $previousMark->getTimestamp() - $candidate->iat->getTimestamp() > self::REBASELINE_NOTE_SECONDS;

            AuditTrail::record($candidate->store, 'LICENSE_RENEWED', null, 'store', $candidate->store, null, [
                'seq' => $candidate->seq,
                'previous_seq' => $current->seq,
                'kid' => $candidate->kid,
                'mode' => $candidate->mode,
                'seats' => $candidate->seats,
                'exp' => $candidate->exp->format('Y-m-d\TH:i:sP'),
                'chain_gap' => ! $linksCleanly,
                'time_rebaselined_from' => $movedBack ? $previousMark->format('Y-m-d\TH:i:sP') : null,
                'control_plane' => $reply->url,
            ]);
        });

        return new RenewalResult(RenewalResult::RENEWED, "Renewed: certificate {$candidate->seq} valid until ".$candidate->exp->format('Y-m-d H:i').'.');
    }

    private function applyRevocation(ControlPlaneReply $reply, Certificate $current, DateTimeImmutable $now): RenewalResult
    {
        try {
            $revocation = Revocation::fromPayload(SignedEnvelope::open((string) ($reply->json['revocation'] ?? ''), $this->gate->keyRing()));
        } catch (CertificateRejected) {
            return $this->failed('The provider reported a revocation that is not signed. It was ignored; nothing was changed.', $now);
        }

        if ($revocation->installation !== $current->installation || $revocation->store !== $current->store) {
            return $this->failed('The revocation is for a different installation. Nothing was changed.', $now);
        }

        DB::transaction(function () use ($revocation, $now) {
            DB::table('license_state')->where('id', 1)->update(['revoked' => true, 'pending_request_id' => null, 'last_renewal_error' => null, 'updated_at' => $this->stamp($now)]);
            AuditTrail::record($revocation->store, 'LICENSE_REVOKED', null, 'store', $revocation->store, null, [
                'kid' => $revocation->kid,
                'iat' => $revocation->iat->format('Y-m-d\TH:i:sP'),
            ]);
        });

        return new RenewalResult(RenewalResult::REVOKED, 'The provider has revoked this installation. Selling is locked until the provider reinstates it.');
    }

    private function failed(string $message, DateTimeImmutable $now): RenewalResult
    {
        DB::table('license_state')->where('id', 1)->update(['last_renewal_error' => mb_substr($message, 0, 200), 'updated_at' => $this->stamp($now)]);

        return new RenewalResult(RenewalResult::FAILED, $message);
    }

    private function stamp(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:sP');
    }
}

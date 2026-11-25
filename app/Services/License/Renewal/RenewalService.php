<?php

namespace App\Services\License\Renewal;

use App\Services\Audit\AuditTrail;
use App\Services\License\Contract\Base64Url;
use App\Services\License\Contract\CanonicalJson;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\Revocation;
use App\Services\License\Contract\SignedEnvelope;
use App\Services\License\FingerprintMatcher;
use App\Services\License\HostHardware;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\RefusalReason;
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
 *
 * A shop with no internet renews by file (ADR-019 slice 3): {@see requestFile()} writes the same request, signed with this
 * installation's key, and {@see applyFile()} applies the provider's reply with the same checks. One difference: a file can be kept
 * and applied weeks later, so its provider time only ever moves the trusted-time mark FORWARD. Only an answer that has just come
 * over the network (or a time-reset code, which is used once) may set the mark back.
 *
 * Keys change (ADR-019 slice 3). A request names the keys this build accepts, and a certificate held under a key this build has
 * since revoked is still sent, to ask for its successor; only what the build accepts is ever applied.
 *
 * A PC locked because its clock fell behind the mark says so in its request: only a certificate newer than the one it holds can
 * re-set the mark, and the provider otherwise answers "unchanged" for 24 hours.
 */
final class RenewalService
{
    /** A mark moved back by more than this when re-baselined is worth an audit note (it is the same tolerance as a clock rollback). */
    private const REBASELINE_NOTE_SECONDS = 3600;

    /** A reply file dated further ahead of this clock than this means the clock is wrong (the rollback tolerance, reused). */
    private const CLOCK_SKEW_SECONDS = 3600;

    private const REQUEST_FILE_TYPE = 'tindaflow.renewal-request';

    public function __construct(
        private readonly LicenseGate $gate,
        private readonly InstallationIdentity $identity,
        private readonly ControlPlane $controlPlane,
        private readonly TrustedClock $clock,
        private readonly HostHardware $hardware,
    ) {}

    public function renew(?DateTimeImmutable $now = null): RenewalResult
    {
        $now ??= CarbonImmutable::now();

        return $this->whileRenewable(fn (object $row): RenewalResult => $this->attempt($row, $now));
    }

    /** The renewal request for a shop with no internet; the result's `document` is the file to send to the provider. */
    public function requestFile(?DateTimeImmutable $now = null): RenewalResult
    {
        $now ??= CarbonImmutable::now();

        return $this->whileRenewable(function (object $row) use ($now): RenewalResult {
            $prepared = $this->prepare($row, $now);
            if ($prepared instanceof RenewalResult) {
                return $prepared;
            }
            [, $identity, $request] = $prepared;

            // The same shape as every signed document here, signed with this installation's own key and named by it (`kid`).
            $payload = CanonicalJson::encode(['typ' => self::REQUEST_FILE_TYPE, 'kid' => $request['installation']] + $request);
            $document = Base64Url::encode($payload).'.'.Base64Url::encode(sodium_crypto_sign_detached($payload, base64_decode($identity['secret_key'], true)));

            return new RenewalResult(RenewalResult::REQUESTED, 'The renewal request is ready.', $document);
        });
    }

    /** Applies the provider's reply to a request file. Nothing in it is trusted unless the vendor signed it. */
    public function applyFile(string $reply, ?DateTimeImmutable $now = null): RenewalResult
    {
        $now ??= CarbonImmutable::now();

        return $this->whileRenewable(function (object $row) use ($reply, $now): RenewalResult {
            // A Windows editor may have saved it with a byte order mark.
            $answer = json_decode(trim(ltrim($reply, "\xEF\xBB\xBF")), true);
            $status = is_array($answer) ? ($answer['status'] ?? null) : null;
            $verifier = new CertificateVerifier($this->gate->keyRing());

            try {
                $current = $this->held($row->certificate);
                $identity = $this->identity->load();
            } catch (CertificateRejected|RuntimeException $e) {
                return $this->failed('The certificate held cannot be used ('.$e->getMessage().').', $now);
            }
            if ($identity['installation'] !== $current->installation) {
                return $this->failed("This computer's identity file does not match the certificate held. Nothing was applied.", $now);
            }

            if ($status === 'REVOKED') {
                return $this->applyRevocation($answer, $current, $now);
            }
            if (in_array($status, ['RENEWED', 'UNCHANGED'], true) && is_string($answer['certificate'] ?? null)) {
                return $this->applyCertificate($answer['certificate'], null, $row->certificate, $current, $verifier, $status === 'UNCHANGED', $now);
            }

            return $this->failed('That file is not a reply from your TindaFlow provider. Nothing was changed.', $now);
        });
    }

    /** @param  callable(object): RenewalResult  $work  run with the license row, one renewal at a time */
    private function whileRenewable(callable $work): RenewalResult
    {
        if ($this->gate->keyRing()->isEmpty()) {
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
            return $work($row);
        } finally {
            $lock->release();
        }
    }

    /**
     * What a renewal asks, online or by file: checked against this installation, with its request id saved first.
     *
     * @return array{0: Certificate, 1: array{installation: string, public_key: string, secret_key: string}, 2: array<string, mixed>}|RenewalResult
     */
    private function prepare(object $row, DateTimeImmutable $now): array|RenewalResult
    {
        try {
            $current = $this->held($row->certificate);
            $identity = $this->identity->load();
        } catch (CertificateRejected|RuntimeException $e) {
            return $this->failed('The certificate held cannot be used ('.$e->getMessage().').', $now);
        }

        if ($identity['installation'] !== $current->installation) {
            return $this->failed("This computer's identity file does not match the certificate held. Nothing was sent.", $now);
        }

        // ADR-018 decision 4: with no fresh hardware file the renewal is refused here, before anything is sent (the provider would refuse it too).
        $hardware = $this->hardware->read($now);
        if (! $hardware->isFresh()) {
            return $this->failed('HOST_HELPER_STALE: the hardware file is '.strtolower($hardware->status).' (it must be under 26 hours old). Nothing was sent; check the host helper task.', $now);
        }

        // Read before the request id is saved, so that it describes the installation as the request found it.
        $clockLocked = $this->isClockLocked($now);
        $requestId = $row->pending_request_id ?? (string) Str::uuid();
        DB::table('license_state')->where('id', 1)->update(['pending_request_id' => $requestId, 'last_renewal_attempt_at' => $this->stamp($now)]);

        return [$current, $identity, [
            'v' => 1,
            'request_id' => $requestId,
            'installation' => $current->installation,
            'certificate' => $row->certificate,
            'fingerprint' => $hardware->fingerprint,
            'trusts' => $this->gate->trustedKeys(),
        ] + ($clockLocked ? ['clock_locked' => true] : [])];
    }

    /** @throws CertificateRejected the certificate held is not one any key in this build made */
    private function held(string $token): Certificate
    {
        return (new CertificateVerifier($this->gate->heldKeyRing()))->verify($token);
    }

    private function isClockLocked(DateTimeImmutable $now): bool
    {
        return $this->gate->check($now)->verdict->shiftOpenRefusal() === RefusalReason::ClockRollback;
    }

    private function attempt(object $row, DateTimeImmutable $now): RenewalResult
    {
        $urls = json_decode((string) $row->control_plane, true);
        if (! is_array($urls) || $urls === []) {
            return $this->failed('No control plane address is stored for this installation.', $now);
        }

        $prepared = $this->prepare($row, $now);
        if ($prepared instanceof RenewalResult) {
            return $prepared;
        }
        [$current, $identity, $request] = $prepared;

        $reply = $this->controlPlane->renew($urls, $request, $current->installation, $identity['secret_key']);

        if ($reply->status === 0) {
            return $this->failed('The control plane could not be reached ('.$reply->error.').', $now);
        }

        $status = $reply->json['status'] ?? null;

        if ($reply->status === 403 && ($reply->json['error']['code'] ?? null) === 'INSTALLATION_REPLACED') {
            // Not signed, so it changes nothing here; it only says why.
            return $this->failed('The provider reports that this computer has been replaced by another and is not renewed any more (HTTP 403). Contact your TindaFlow provider.', $now);
        }

        if ($reply->status === 403 && ($reply->json['error']['code'] ?? null) === 'FINGERPRINT_MISMATCH') {
            // Kept short enough to be stored whole. The provider approves changed hardware by this reference (ADR-019 slice 3).
            $reference = FingerprintMatcher::reference($request['fingerprint']);

            return $this->failed("The provider says the hardware does not match this license (HTTP 403). If this computer was repaired, give your provider hardware reference {$reference}. Nothing was changed.", $now);
        }

        if ($reply->status === 403 && ($reply->json['error']['code'] ?? null) === 'SOFTWARE_UPDATE_REQUIRED') {
            return $this->failed("The provider has changed its key, and this computer's TindaFlow software does not accept the new one. Ask your TindaFlow provider for the update (HTTP 403). Nothing was changed.", $now);
        }

        if ($reply->status === 409) {
            // Kept short enough to be stored whole. The provider approves a reconnection by this number (ADR-019 slice 3).
            return $this->failed("The provider reports a forked certificate chain. Nothing was changed. If a backup was restored, ask your TindaFlow provider to reconnect it: this computer holds certificate {$current->seq}.", $now);
        }

        if ($reply->status === 200 && $status === 'REVOKED') {
            return $this->applyRevocation($reply->json, $current, $now);
        }

        if ($reply->status === 200 && in_array($status, ['RENEWED', 'UNCHANGED'], true) && is_string($reply->json['certificate'] ?? null)) {
            return $this->applyCertificate((string) $reply->json['certificate'], (string) $reply->url, $row->certificate, $current, new CertificateVerifier($this->gate->keyRing()), $status === 'UNCHANGED', $now);
        }

        return $this->failed("Unexpected answer from the control plane (HTTP {$reply->status}).", $now);
    }

    /** @param  string|null  $url  where the answer came from just now, or null when it came as a file */
    private function applyCertificate(string $token, ?string $url, string $currentToken, Certificate $current, CertificateVerifier $verifier, bool $claimsUnchanged, DateTimeImmutable $now): RenewalResult
    {
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

            return new RenewalResult(RenewalResult::UNCHANGED, 'The certificate is still current (valid until '.$current->exp->format('Y-m-d H:i').').'.$this->clockLockNote($now, 'Try again in an hour, or ask your provider for a time-reset code.'));
        }

        if ($claimsUnchanged) {
            return $this->failed('The provider said the certificate was unchanged but sent a different one. Nothing was changed.', $now);
        }

        if ($url === null && $candidate->iat->getTimestamp() - $now->getTimestamp() > self::CLOCK_SKEW_SECONDS) {
            return $this->failed("This computer's clock is more than an hour behind the provider's. Correct the date and time, then apply the file again. Nothing was changed.", $now);
        }

        $linksCleanly = ChainRules::linksCleanly($currentToken, $current, $candidate);

        DB::transaction(function () use ($token, $current, $candidate, $linksCleanly, $url, $now) {
            if ($url === null) {
                // A file may have waited weeks: its time can show that time has passed, never that less of it has.
                $this->clock->advanceTo($candidate->iat);
                $previousMark = null;
            } else {
                $previousMark = $this->clock->rebaseline($candidate->iat, $this->hardware->read($now)->generatedAt);
            }

            DB::table('license_state')->where('id', 1)->update([
                'certificate' => $token,
                'certificate_seq' => $candidate->seq,
                // A new certificate discards any extension of the old one and starts the count of codes in a row again (ADR-018 decision 6).
                'extended_until' => null,
                'extension_base_seq' => null,
                'extensions_in_a_row' => 0,
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
                'via' => $url === null ? 'file' : 'online',
                'control_plane' => $url,
            ]);
        });

        return new RenewalResult(RenewalResult::RENEWED, "Renewed: certificate {$candidate->seq} valid until ".$candidate->exp->format('Y-m-d H:i').'.'.$this->clockLockNote($now, 'Ask your provider for a time-reset code.'));
    }

    private function clockLockNote(DateTimeImmutable $now, string $next): string
    {
        return $this->isClockLocked($now) ? " Selling is still locked because the clock is behind the recorded time. Check this computer's date and time. {$next}" : '';
    }

    /** @param  array<string, mixed>|null  $answer */
    private function applyRevocation(?array $answer, Certificate $current, DateTimeImmutable $now): RenewalResult
    {
        try {
            $revocation = Revocation::fromPayload(SignedEnvelope::open((string) ($answer['revocation'] ?? ''), $this->gate->keyRing()));
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

        return new RenewalResult(RenewalResult::REVOKED, 'The provider has revoked this installation. Selling is locked, and it cannot be unlocked from here. Contact your TindaFlow provider.');
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

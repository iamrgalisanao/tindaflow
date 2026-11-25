<?php

namespace App\Services\License;

use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\KeyRing;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gathers what this installation knows (the build's key ring, the stored certificate and its verdicts, the trusted-time mark)
 * and asks the pure {@see LicenseEvaluator} what the license state is. A build with no vendor key answers LEGACY straight
 * away without reading any table, so nothing changes for an installation that has not adopted ADR-018.
 *
 * Read-only: it never writes, so it is safe to call from a request. The fingerprint comes from the host helper, which is not
 * built yet; until then it is unknown, and an unknown fingerprint changes no state (ADR-018 decision 4).
 */
final class LicenseGate
{
    public function __construct(
        private readonly Entitlement $entitlement,
        private readonly LicenseEvaluator $evaluator,
        private readonly HostHardware $hardware,
        private readonly InstallationIdentity $identity,
    ) {}

    public function keyRing(): KeyRing
    {
        return new KeyRing((array) config('tindaflow.license.vendor_keys'), (array) config('tindaflow.license.revoked_kids'));
    }

    /**
     * For reading the certificate HELD when asking the provider for its successor, and for nothing else (ADR-019 slice 3, rotating
     * keys): the same public keys, revoked ones included. A build that distrusts a key counts a certificate under it as none, but the
     * shop must still be able to say which certificate it holds, or it could never get one under the new key. What comes back is
     * judged with {@see KeyRing()}. A key whose public half has been taken out of the build cannot even be read this way.
     */
    public function heldKeyRing(): KeyRing
    {
        return new KeyRing((array) config('tindaflow.license.vendor_keys'));
    }

    /**
     * The keys this build accepts, told to the provider with each renewal so that it signs nothing this build would refuse.
     *
     * @return array{kids: list<string>, code_keys: list<int>}
     */
    public function trustedKeys(): array
    {
        $kids = array_diff(array_map('strval', array_keys((array) config('tindaflow.license.vendor_keys'))), (array) config('tindaflow.license.revoked_kids'));
        $codeKeys = array_diff(array_keys((array) config('tindaflow.license.code_keys')), (array) config('tindaflow.license.revoked_code_keys'));
        sort($kids, SORT_STRING);
        sort($codeKeys, SORT_NUMERIC);

        return ['kids' => $kids, 'code_keys' => $codeKeys];
    }

    public function check(?DateTimeImmutable $wallNow = null): LicenseCheck
    {
        $wallNow ??= CarbonImmutable::now();
        $ring = $this->keyRing();

        if ($ring->isEmpty()) {
            $facts = new LocalFacts($wallNow);

            return new LicenseCheck($this->evaluator->evaluate(null, $facts, false), $facts->trustedNow());
        }

        $row = DB::table('license_state')->where('id', 1)->first();
        $certificate = $this->certificate($row->certificate ?? null);
        // An extension belongs to the one certificate it was made for; a renewal replaces that certificate and so drops it.
        $extendedUntil = $certificate !== null && ($row->extended_until ?? null) !== null && (int) $row->extension_base_seq === $certificate->seq
            ? new DateTimeImmutable($row->extended_until)
            : null;
        $facts = new LocalFacts(
            wallNow: $wallNow,
            trustedTimeHwm: ($row->trusted_time_hwm ?? null) === null ? null : new DateTimeImmutable($row->trusted_time_hwm),
            // From the host helper's file when it is fresh; a missing or stale file is null, which changes no state (ADR-018 decision 4).
            observedFingerprint: $this->hardware->read($wallNow)->fingerprint,
            revoked: (bool) ($row->revoked ?? false),
            chainInvalid: (bool) ($row->chain_invalid ?? false),
            extendedUntil: $extendedUntil,
        );

        // An ADR-014 till license counts only until the installation is activated. Once a certificate has been stored, one that is no
        // longer accepted is NOT_ACTIVATED: an old license file left behind must never switch the license off (ADR-019 slice 4).
        $publicKey = (string) config('tindaflow.license.public_key');
        $legacyLicensePresent = ($row->certificate ?? null) === null && $publicKey !== '' && $this->entitlement->verifiedPayload($publicKey) !== null;

        return new LicenseCheck($this->evaluator->evaluate($certificate, $facts, true, $legacyLicensePresent), $facts->trustedNow(), $certificate?->seats);
    }

    /**
     * The stored certificate, if it counts: this build accepts its signature, it was issued for this computer's identity, and this
     * database holds the store it names (ADR-019 defect 3). Otherwise it is the same as having none. The copied license state of
     * another installation or shop is therefore worth nothing here. It is not protection against someone who copies the whole
     * installation, identity file included.
     */
    public function certificate(?string $token): ?Certificate
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $certificate = (new CertificateVerifier($this->keyRing()))->verify($token);
        } catch (CertificateRejected $e) {
            // A stored certificate this build no longer accepts (for example its key was revoked) is the same as having none.
            return $this->rejected($e->reason);
        }

        try {
            $installation = $this->identity->load()['installation'];
        } catch (RuntimeException) {
            return $this->rejected('NO_IDENTITY');
        }

        if ($certificate->installation !== $installation) {
            return $this->rejected('OTHER_INSTALLATION');
        }

        return DB::table('stores')->where('id', $certificate->store)->exists() ? $certificate : $this->rejected('OTHER_STORE');
    }

    private function rejected(string $reason): null
    {
        Log::warning('license.stored_certificate_rejected', ['reason' => $reason]);

        return null;
    }
}

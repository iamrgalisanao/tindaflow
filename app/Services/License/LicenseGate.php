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
    public function __construct(private readonly Entitlement $entitlement, private readonly LicenseEvaluator $evaluator) {}

    public function keyRing(): KeyRing
    {
        return new KeyRing((array) config('tindaflow.license.vendor_keys'), (array) config('tindaflow.license.revoked_kids'));
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
        $facts = new LocalFacts(
            wallNow: $wallNow,
            trustedTimeHwm: ($row->trusted_time_hwm ?? null) === null ? null : new DateTimeImmutable($row->trusted_time_hwm),
            observedFingerprint: null,
            revoked: (bool) ($row->revoked ?? false),
            chainInvalid: (bool) ($row->chain_invalid ?? false),
        );

        $publicKey = (string) config('tindaflow.license.public_key');
        $legacyLicensePresent = $publicKey !== '' && $this->entitlement->verifiedPayload($publicKey) !== null;

        return new LicenseCheck($this->evaluator->evaluate($this->certificate($ring, $row->certificate ?? null), $facts, true, $legacyLicensePresent), $facts->trustedNow());
    }

    private function certificate(KeyRing $ring, ?string $token): ?Certificate
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            return (new CertificateVerifier($ring))->verify($token);
        } catch (CertificateRejected $e) {
            // A stored certificate this build no longer accepts (for example its key was revoked) is the same as having none.
            Log::warning('license.stored_certificate_rejected', ['reason' => $e->reason]);

            return null;
        }
    }
}

<?php

namespace App\Services\License;

use App\Domain\Exceptions\LicenseNotActiveException;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Time\TrustedClock;
use App\Support\DeploymentId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * What the License screen shows (ADR-018 decision 10, openapi `LicenseStatus`): the state the gate computes, why a new shift would
 * be refused, how long the license runs, the certificate number the provider needs for an offline code, and the last renewal and
 * hardware-file facts that explain a problem. It never returns the certificate, a key or the hardware hashes. In a build with no
 * vendor key it says LEGACY and reads no license table.
 */
final class LicenseStatusReport
{
    public function __construct(private readonly LicenseGate $gate, private readonly TrustedClock $clock, private readonly HostHardware $hardware) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $empty = [
            'state' => LicenseState::Legacy->value, 'enforcing' => false, 'reason' => null, 'message' => null,
            'deployment_code' => DeploymentId::current(), 'certificate_number' => null, 'seats' => null,
            'valid_until' => null, 'certificate_expires_at' => null, 'extended_until' => null,
            'extensions_used' => 0, 'extensions_allowed' => OfflineCodeService::MAX_EXTENSIONS_IN_A_ROW,
            'trusted_time' => null, 'last_renewed_at' => null, 'last_renewal_attempt_at' => null, 'last_renewal_error' => null,
            'hardware_file' => null, 'accepts_offline_codes' => false,
        ];

        $ring = $this->gate->keyRing();
        if ($ring->isEmpty()) {
            return $empty;
        }

        $check = $this->gate->check();
        $refusal = $check->verdict->shiftOpenRefusal();
        $row = DB::table('license_state')->where('id', 1)->first();
        $certificate = $this->certificate($row->certificate ?? null);
        $extendedUntil = $certificate !== null && $row->extended_until !== null && (int) $row->extension_base_seq === $certificate->seq
            ? new DateTimeImmutable($row->extended_until)
            : null;
        $reading = $this->hardware->read();

        return [
            'state' => $check->verdict->state->value,
            'enforcing' => $check->verdict->enforcing,
            'reason' => $refusal?->value,
            'message' => $refusal === null ? null : LicenseNotActiveException::make($refusal, $check->verdict->state)->getMessage(),
            'certificate_number' => $certificate?->seq,
            'seats' => $certificate?->seats,
            'valid_until' => $certificate === null ? null : $this->time($extendedUntil !== null && $extendedUntil > $certificate->exp ? $extendedUntil : $certificate->exp),
            'certificate_expires_at' => $certificate === null ? null : $this->time($certificate->exp),
            'extended_until' => $this->time($extendedUntil),
            'extensions_used' => (int) ($row->extensions_in_a_row ?? 0),
            'trusted_time' => $this->time($this->clock->highWaterMark()),
            'last_renewed_at' => $this->time($this->parse($row->last_renewed_at ?? null)),
            'last_renewal_attempt_at' => $this->time($this->parse($row->last_renewal_attempt_at ?? null)),
            'last_renewal_error' => $row->last_renewal_error ?? null,
            'hardware_file' => ['status' => $reading->status, 'generated_at' => $this->time($reading->generatedAt)],
            'accepts_offline_codes' => $certificate !== null && (array) config('tindaflow.license.code_keys') !== [],
        ] + $empty;
    }

    private function certificate(?string $token): ?Certificate
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            return (new CertificateVerifier($this->gate->keyRing()))->verify($token);
        } catch (CertificateRejected) {
            return null;
        }
    }

    private function parse(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }

    /** ISO 8601 with the store's offset. */
    private function time(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone((string) config('app.timezone')))->format('Y-m-d\TH:i:sP');
    }
}

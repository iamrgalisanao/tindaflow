<?php

namespace App\Services\License;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What the till tells whoever is selling about the license (ADR-019, till warning): that it is about to run out, or that a new
 * shift can no longer be opened and when the one that is open stops selling. It says nothing unless something will actually be
 * refused: nothing in a build with no vendor key, and nothing for a certificate in report mode. It carries no certificate,
 * deployment code or other license detail, so any signed-in user of the till may read it.
 */
final class TillNotice
{
    public const EXPIRING = 'EXPIRING';

    public const NEW_SHIFT_REFUSED = 'NEW_SHIFT_REFUSED';

    public function __construct(private readonly LicenseGate $gate) {}

    /**
     * @param  DateTimeImmutable|null  $openShiftSince  when the till's open shift was opened; null when none is open
     * @return array{kind: string, valid_until: string|null, reason: string|null, sales_stop_at: string|null, sales_stopped: bool}|null
     */
    public function for(?DateTimeImmutable $openShiftSince): ?array
    {
        if ($this->gate->keyRing()->isEmpty()) {
            return null;
        }

        $check = $this->gate->check();
        $verdict = $check->verdict;
        if (! $verdict->enforcing) {
            return null;
        }

        if ($verdict->state === LicenseState::Warning) {
            return ['kind' => self::EXPIRING, 'valid_until' => $this->time($check->validUntil), 'reason' => null, 'sales_stop_at' => null, 'sales_stopped' => false];
        }

        $refusal = $verdict->shiftOpenRefusal();
        if ($refusal === null) {
            return null;
        }

        return [
            'kind' => self::NEW_SHIFT_REFUSED,
            'valid_until' => null,
            'reason' => $refusal->value,
            'sales_stop_at' => $openShiftSince === null ? null : $this->time($openShiftSince->modify('+'.LicenseVerdict::OPEN_SHIFT_CAP_SECONDS.' seconds')),
            // Judged on trusted time, like the refusal itself: a computer clock set back does not give the shift longer.
            'sales_stopped' => $openShiftSince !== null && $verdict->saleRefusal($openShiftSince, $check->trustedNow) !== null,
        ];
    }

    /** ISO 8601 with the store's offset. */
    private function time(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone((string) config('app.timezone')))->format('Y-m-d\TH:i:sP');
    }
}

<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\TerminalLimitReachedException;
use App\Models\Terminal;
use App\Services\License\Entitlement;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use Illuminate\Support\Facades\Log;

/**
 * ADR-014: counts the tills this installation may run against its signed license. A "seat" is a terminal that is neither
 * revoked nor decommissioned, so a stolen till can be revoked and replaced, but re-enrolling a revoked one takes a seat
 * again and is checked like a new one.
 *
 * Where the limit comes from (ADR-018, its amendment of ADR-015; ADR-019 slice 4):
 *
 * - LEGACY (a build with no vendor key, or a shop not yet activated that still has its ADR-014 license): the ADR-014 license, as before;
 * - ACTIVE or WARNING: the `seats` of the activation certificate;
 * - NOT_ACTIVATED, EXPIRED or LOCKED: no new till;
 * - a certificate in report mode: no limit; what would be refused is logged.
 *
 * Only ADDING a till (or putting a revoked one back) is ever limited. A till already set up is never blocked by this.
 */
final class TerminalSeats
{
    public function __construct(private readonly Entitlement $entitlement, private readonly LicenseGate $gate) {}

    public function inUse(?string $exceptTerminalId = null): int
    {
        return Terminal::whereNull('revoked_at')
            ->where('status', '!=', 'DECOMMISSIONED')
            ->when($exceptTerminalId, fn ($query) => $query->where('id', '!=', $exceptTerminalId))
            ->count();
    }

    /** null = no cap is enforced on this installation. */
    public function maximum(): ?int
    {
        $limit = $this->limit();

        return $limit['enforced'] ? $limit['maximum'] : null;
    }

    /** @throws TerminalLimitReachedException */
    public function assertRoomForOneMore(?string $exceptTerminalId = null): void
    {
        ['maximum' => $maximum, 'enforced' => $enforced, 'lapsed' => $lapsed] = $this->limit();
        if ($maximum === null) {
            return;
        }

        $inUse = $this->inUse($exceptTerminalId);
        if ($inUse < $maximum) {
            return;
        }
        if (! $enforced) {
            Log::warning('license.would_refuse', ['gate' => 'tillAdd', 'reason' => 'TERMINAL_LIMIT_REACHED', 'state' => $lapsed?->value, 'max_terminals' => $maximum, 'in_use' => $inUse]);

            return;
        }

        throw TerminalLimitReachedException::make($maximum, $inUse, $lapsed);
    }

    /**
     * For the one till an activation creates, whose certificate is verified but not yet the stored one.
     *
     * @throws TerminalLimitReachedException
     */
    public function assertRoomWithin(int $maximum): void
    {
        $inUse = $this->inUse();
        if ($inUse >= $maximum) {
            throw TerminalLimitReachedException::make($maximum, $inUse);
        }
    }

    /**
     * @return array{maximum: int|null, enforced: bool, lapsed: LicenseState|null} `lapsed` is the license state when that, and not
     *                                                                             the number of tills, is why no till can be added
     */
    private function limit(): array
    {
        $check = $this->gate->check();
        $state = $check->verdict->state;

        if ($state === LicenseState::Legacy) {
            return ['maximum' => $this->entitlement->maxTerminals(), 'enforced' => true, 'lapsed' => null];
        }

        $current = $state === LicenseState::Active || $state === LicenseState::Warning;

        return ['maximum' => $current ? (int) $check->seats : 0, 'enforced' => $check->verdict->enforcing, 'lapsed' => $current ? null : $state];
    }
}

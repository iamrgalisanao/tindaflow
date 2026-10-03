<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\TerminalLimitReachedException;
use App\Models\Terminal;
use App\Services\License\Entitlement;

/**
 * ADR-014: counts the tills this installation may run against its signed license. A "seat" is a terminal that is neither
 * revoked nor decommissioned, so a stolen till can be revoked and replaced, but re-enrolling a revoked one takes a seat
 * again and is checked like a new one.
 */
final class TerminalSeats
{
    public function __construct(private readonly Entitlement $entitlement) {}

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
        return $this->entitlement->maxTerminals();
    }

    /** @throws TerminalLimitReachedException */
    public function assertRoomForOneMore(?string $exceptTerminalId = null): void
    {
        $maximum = $this->maximum();

        if ($maximum !== null && $this->inUse($exceptTerminalId) >= $maximum) {
            throw TerminalLimitReachedException::make($maximum, $this->inUse($exceptTerminalId));
        }
    }
}

<?php

namespace App\Domain\Exceptions;

/** error-catalog.md CHEQUE_ALLOCATION_EXCEEDS_OWED: an allocation is more than the period currently owes (BILL-002); nothing was recorded. */
final class ChequeAllocationExceedsOwedException extends DomainException
{
    public static function forPeriod(string $periodStart, string $periodEnd, string $owed, string $requested): self
    {
        $why = bccomp($owed, '0', 2) <= 0
            ? 'nothing is owed for it (no credit charges, or it is already paid in full)'
            : "only P{$owed} is still owed";

        return new self("A cheque cannot pay more than is owed. For the period {$periodStart} to {$periodEnd}, ".$why.", and P{$requested} was allocated to it.", [
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'owed' => $owed, 'requested' => $requested,
        ]);
    }

    public function errorCode(): string
    {
        return 'CHEQUE_ALLOCATION_EXCEEDS_OWED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

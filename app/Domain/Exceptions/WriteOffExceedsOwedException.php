<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_EXCEEDS_OWED: a write-off is more than the period currently owes (BILL-008); nothing was written off. */
final class WriteOffExceedsOwedException extends DomainException
{
    public static function forPeriod(string $periodStart, string $periodEnd, string $owed, string $requested): self
    {
        $why = bccomp($owed, '0', 2) <= 0
            ? 'nothing is owed for it (no charges, or it is already paid or written off in full)'
            : "only P{$owed} is still owed";

        return new self("You cannot write off more than is owed. For the period {$periodStart} to {$periodEnd}, ".$why.", and P{$requested} was entered.", [
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'owed' => $owed, 'requested' => $requested,
        ]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_EXCEEDS_OWED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

<?php

namespace App\Domain\Exceptions;

use App\Services\License\LicenseState;

/** error-catalog.md TERMINAL_LIMIT_REACHED: the installation's signed license allows no more active tills (ADR-014). */
final class TerminalLimitReachedException extends DomainException
{
    /** @param  LicenseState|null  $lapsed  the activation state that allows no new till, when that is the reason */
    public static function make(int $maximum, int $inUse, ?LicenseState $lapsed = null): self
    {
        return new self(
            match (true) {
                $lapsed !== null => "No till can be added while the license is {$lapsed->value}. Tills already set up keep working. See the License screen, or tindaflow:license-status.",
                $maximum === 0 => 'This installation has no valid license for any till. Ask your TindaFlow provider for a license.',
                default => "This installation is licensed for {$maximum} till".($maximum === 1 ? '' : 's').' and all are in use. Revoke a till you no longer use, or ask your TindaFlow provider to raise the limit.',
            },
            ['max_terminals' => $maximum, 'in_use' => $inUse],
        );
    }

    public function errorCode(): string
    {
        return 'TERMINAL_LIMIT_REACHED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

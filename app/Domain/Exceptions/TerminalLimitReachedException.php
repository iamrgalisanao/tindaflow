<?php

namespace App\Domain\Exceptions;

/** error-catalog.md TERMINAL_LIMIT_REACHED: the installation's signed license allows no more active tills (ADR-014). */
final class TerminalLimitReachedException extends DomainException
{
    public static function make(int $maximum, int $inUse): self
    {
        return new self(
            $maximum === 0
                ? 'This installation has no valid license for any till. Ask your TindaFlow provider for a license.'
                : "This installation is licensed for {$maximum} till".($maximum === 1 ? '' : 's').' and all are in use. Revoke a till you no longer use, or ask your TindaFlow provider to raise the limit.',
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

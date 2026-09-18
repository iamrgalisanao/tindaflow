<?php

namespace App\Domain\Exceptions;

/** error-catalog.md TERMINAL_NOT_FOUND. */
final class TerminalNotFoundException extends DomainException
{
    public static function forId(string $terminalId): self
    {
        return new self("Terminal \"{$terminalId}\" was not found.", ['terminal_id' => $terminalId]);
    }

    public function errorCode(): string
    {
        return 'TERMINAL_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}

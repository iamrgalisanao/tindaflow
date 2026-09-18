<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md TERMINAL_REVOKED. ADR-011: this browser's credential
 * resolves to a real terminal whose credential has since been revoked
 * (terminals.revoked_at IS NOT NULL) -- distinct from
 * TerminalNotEnrolledException (unknown/missing credential), per §14
 * Ruling 3's explicit "revoked_at vs. TerminalStatus" independence.
 */
final class TerminalRevokedException extends DomainException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self("This browser's terminal credential was revoked.", ['terminal_id' => $terminalId]);
    }

    public function errorCode(): string
    {
        return 'TERMINAL_REVOKED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}

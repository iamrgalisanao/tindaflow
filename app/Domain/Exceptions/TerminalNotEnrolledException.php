<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md TERMINAL_NOT_ENROLLED. ADR-011: this browser presented
 * no `tindaflow_terminal` credential, or the credential does not match
 * any terminal's credential_hash. Deliberately the SAME public outcome
 * for "no cookie" and "unknown cookie" -- the frozen contract does not
 * distinguish them (module-a-auth-terminal-initialization.md §13/§14).
 */
final class TerminalNotEnrolledException extends DomainException
{
    public static function make(): self
    {
        return new self('This browser is not enrolled as any terminal.');
    }

    public function errorCode(): string
    {
        return 'TERMINAL_NOT_ENROLLED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}

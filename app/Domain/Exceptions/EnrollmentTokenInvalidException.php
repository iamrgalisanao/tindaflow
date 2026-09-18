<?php

namespace App\Domain\Exceptions;

/**
 * openapi.yaml terminalEnroll's 409 ("Token already used, expired, or
 * revoked") references the generic Error schema with NO code registered
 * in error-catalog.md -- confirmed by direct inspection, the same kind
 * of gap A1 found for structural login validation. UNLIKE that gap,
 * this one is NOT deferred: the required A3 test matrix (items 8-9)
 * requires unknown/reused-token rejection to actually work end to end,
 * so a real code is needed now to have anything testable.
 *
 * `ENROLLMENT_TOKEN_INVALID` (409) is a PROPOSED candidate, proposed
 * (not yet added to error-catalog.md) pending the owner's ruling, per
 * this project's established discipline: check for an existing code
 * first (CONCURRENCY_CONFLICT was considered and rejected -- its own
 * description is scoped to "a current-state race," which fits "token
 * already used" but not "expired" or "unknown", and the frozen contract
 * describes all three as ONE outcome, not three to be split across
 * codes), then propose the smallest new one if none fits, and STOP
 * before touching error-catalog.md rather than silently repeating
 * A1/A2's forward-edit mistake. See the A3 final report.
 */
final class EnrollmentTokenInvalidException extends DomainException
{
    public static function make(): self
    {
        return new self('This enrollment token does not exist, has already been used, or has expired.');
    }

    public function errorCode(): string
    {
        return 'ENROLLMENT_TOKEN_INVALID';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

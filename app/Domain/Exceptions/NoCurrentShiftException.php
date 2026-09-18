<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md NO_CURRENT_SHIFT. openapi.yaml's shiftCurrentGet
 * declares its "no open shift" case as a 404 -- matching error-catalog.md's
 * own generic HTTP-status-semantics table, which names "no open shift"
 * as its literal example of a 404 ("a resource whose existence is
 * itself conditional"). The only existing shift-related code with a
 * similar name, SHIFT_NOT_OPEN, is frozen at 409 for a different
 * scenario (a shift already referenced by ID, found not open -- e.g. a
 * cash movement against a closed shift), and error-catalog.md's own
 * stable code->status rule forbids reusing it at 404 here. SHIFT_REQUIRED
 * (409) is the closest semantic match but is equally frozen at 409, not
 * 404. No existing code fits; this is a genuine new Stage 4 amendment,
 * not a business/legal judgment call, so no owner escalation is required.
 */
final class NoCurrentShiftException extends DomainException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self("Terminal \"{$terminalId}\" has no currently open shift.", ['terminal_id' => $terminalId]);
    }

    public function errorCode(): string
    {
        return 'NO_CURRENT_SHIFT';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}

<?php

namespace App\Domain\Exceptions;

/**
 * ADR-017 section 9, invariants.md STK-005: a refusal of the manager override of a blocked sale. Four stable codes, mirroring the credit
 * override's (CreditOverrideException):
 *   STOCK_OVERRIDE_INVALID      422  the code did not work, or the approver cannot approve; one answer for every such case. Also an unknown token.
 *   STOCK_OVERRIDE_LOCKED       423  five wrong codes: this approver is locked for fifteen minutes (shared with the credit override codes).
 *   STOCK_OVERRIDE_EXPIRED      409  the grant is past its five minutes, or was already used.
 *   STOCK_OVERRIDE_NOT_ALLOWED  403  the approver is the cashier, the grant belongs to another terminal or cashier or to a different cart,
 *                                    its approver can no longer approve, there is nothing to override, or the store has switched overrides off.
 * `reason` is the finer reason for the audit log (STOCK_OVERRIDE_REFUSED) and never goes to the client; `auditContext` carries ids and
 * quantities, never a code or a token.
 */
final class StockOverrideException extends DomainException
{
    /** @param  array<string, mixed>  $auditContext */
    private function __construct(
        private readonly string $errorKey,
        private readonly int $status,
        string $message,
        public readonly string $reason,
        public readonly array $auditContext = [],
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function invalid(string $reason, array $auditContext = []): self
    {
        return new self('STOCK_OVERRIDE_INVALID', 422, 'That code did not work.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function locked(string $reason, int $minutes, array $auditContext = []): self
    {
        return new self(
            'STOCK_OVERRIDE_LOCKED', 423,
            "Too many wrong codes. This approver is locked for {$minutes} minutes. Ask another manager, or wait.",
            $reason, $auditContext, ['retry_after_minutes' => $minutes],
        );
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function expired(string $reason, array $auditContext = []): self
    {
        return new self('STOCK_OVERRIDE_EXPIRED', 409, 'This approval has expired or was already used. Ask a manager to approve again.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function notAllowed(string $reason, string $message, array $auditContext = []): self
    {
        return new self('STOCK_OVERRIDE_NOT_ALLOWED', 403, $message, $reason, $auditContext);
    }

    public function errorCode(): string
    {
        return $this->errorKey;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}

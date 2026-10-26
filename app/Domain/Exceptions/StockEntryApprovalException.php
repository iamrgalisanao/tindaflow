<?php

namespace App\Domain\Exceptions;

/**
 * ADR-017 slice B, client decision 5, invariants.md STK-007: a refusal of the manager's approval of a CASHIER's daily stock entry. Mirrors the
 * credit and stock overrides' stable codes:
 *   STOCK_ENTRY_APPROVAL_REQUIRED   403  a cashier tried to enter stock without a manager's approval token.
 *   STOCK_ENTRY_APPROVAL_INVALID    422  the code did not work, or the approver cannot approve; one answer for every such case. Also an unknown token.
 *   STOCK_ENTRY_APPROVAL_LOCKED     423  five wrong codes: this approver is locked for fifteen minutes (shared with the other override codes).
 *   STOCK_ENTRY_APPROVAL_EXPIRED    409  the approval is past its five minutes, or was already used.
 *   STOCK_ENTRY_APPROVAL_NOT_ALLOWED 403 the approver is the cashier, the approval belongs to another terminal, cashier or stock day, or its approver
 *                                        can no longer approve.
 * `reason` is the finer reason for the audit log and never goes to the client; `auditContext` carries ids, never a code or a token.
 */
final class StockEntryApprovalException extends DomainException
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

    public static function required(): self
    {
        return new self('STOCK_ENTRY_APPROVAL_REQUIRED', 403, "Only a manager can enter today's stock. Ask a manager to approve with their code.", 'APPROVAL_REQUIRED');
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function invalid(string $reason, array $auditContext = []): self
    {
        return new self('STOCK_ENTRY_APPROVAL_INVALID', 422, 'That code did not work.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function locked(string $reason, int $minutes, array $auditContext = []): self
    {
        return new self(
            'STOCK_ENTRY_APPROVAL_LOCKED', 423,
            "Too many wrong codes. This approver is locked for {$minutes} minutes. Ask another manager, or wait.",
            $reason, $auditContext, ['retry_after_minutes' => $minutes],
        );
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function expired(string $reason, array $auditContext = []): self
    {
        return new self('STOCK_ENTRY_APPROVAL_EXPIRED', 409, 'This approval has expired or was already used. Ask a manager to approve again.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function notAllowed(string $reason, string $message, array $auditContext = []): self
    {
        return new self('STOCK_ENTRY_APPROVAL_NOT_ALLOWED', 403, $message, $reason, $auditContext);
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

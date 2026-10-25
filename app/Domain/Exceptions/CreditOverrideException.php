<?php

namespace App\Domain\Exceptions;

/**
 * ADR-016 section 6, invariants.md OVR-001, OVR-002: a refusal of the supervisor override of the credit limit. Four stable codes:
 *   CREDIT_OVERRIDE_INVALID      422  the code did not work, or the approver cannot approve. Deliberately one answer for a wrong code, an
 *                                     approver that does not exist, one without a code, an inactive one and one who lacks the capability,
 *                                     so the response never says which approver holds a code. Also an unknown token at checkout.
 *   CREDIT_OVERRIDE_LOCKED       423  five wrong codes: this approver's override is locked for fifteen minutes.
 *   CREDIT_OVERRIDE_EXPIRED      409  the grant is past its five minutes, or was already used.
 *   CREDIT_OVERRIDE_NOT_ALLOWED  403  the approver is the cashier, or the grant belongs to another terminal / employee / cashier, or is for
 *                                     less than this sale, or its approver can no longer approve.
 * `reason` is the finer reason that goes to the audit log (CREDIT_OVERRIDE_REFUSED) and never to the client; `auditContext` is what the
 * audit event should carry (ids and amounts, never a code or a token).
 */
final class CreditOverrideException extends DomainException
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
        return new self('CREDIT_OVERRIDE_INVALID', 422, 'That code did not work.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function locked(string $reason, int $minutes, array $auditContext = []): self
    {
        return new self(
            'CREDIT_OVERRIDE_LOCKED', 423,
            "Too many wrong codes. This approver is locked for {$minutes} minutes. Ask another manager, or wait.",
            $reason, $auditContext, ['retry_after_minutes' => $minutes],
        );
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function expired(string $reason, array $auditContext = []): self
    {
        return new self('CREDIT_OVERRIDE_EXPIRED', 409, 'This approval has expired or was already used. Ask a manager to approve again.', $reason, $auditContext);
    }

    /** @param  array<string, mixed>  $auditContext */
    public static function notAllowed(string $reason, string $message, array $auditContext = []): self
    {
        return new self('CREDIT_OVERRIDE_NOT_ALLOWED', 403, $message, $reason, $auditContext);
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

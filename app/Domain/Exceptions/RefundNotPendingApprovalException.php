<?php

namespace App\Domain\Exceptions;

/** state-machines.md SS3 / error-catalog.md REFUND_NOT_PENDING_APPROVAL: approve/reject called on a refund that is no longer REQUESTED. */
final class RefundNotPendingApprovalException extends DomainException
{
    public static function forRefund(string $refundId, string $status): self
    {
        return new self('This refund is no longer waiting for a decision.', ['refund_id' => $refundId, 'status' => $status]);
    }

    public function errorCode(): string
    {
        return 'REFUND_NOT_PENDING_APPROVAL';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

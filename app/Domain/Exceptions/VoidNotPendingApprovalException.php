<?php

namespace App\Domain\Exceptions;

/** state-machines.md SS2 / error-catalog.md VOID_NOT_PENDING_APPROVAL: approve/reject called on a void that is no longer REQUESTED. */
final class VoidNotPendingApprovalException extends DomainException
{
    public static function forVoid(string $voidId, string $status): self
    {
        return new self('This void is no longer waiting for a decision.', ['void_id' => $voidId, 'status' => $status]);
    }

    public function errorCode(): string
    {
        return 'VOID_NOT_PENDING_APPROVAL';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

<?php

namespace App\Domain\Exceptions;

/** error-catalog.md BILLING_RUN_NOT_FOUND: no such saved billing run in this store (a run of another store is indistinguishable). */
final class BillingRunNotFoundException extends DomainException
{
    public static function forId(string $runId): self
    {
        return new self('The referenced billing run does not exist.', ['billing_run_id' => $runId]);
    }

    public function errorCode(): string
    {
        return 'BILLING_RUN_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}

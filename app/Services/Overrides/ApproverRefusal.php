<?php

namespace App\Services\Overrides;

use RuntimeException;

/**
 * Why an approver's code was not accepted, in the neutral terms {@see ApproverCodeVerifier} speaks. Each override family (credit limit,
 * stock) turns it into its own domain exception with its own stable error code. `kind` is INVALID, LOCKED or NOT_ALLOWED; `reason` is the
 * finer reason that goes to the audit log and never to the client.
 */
final class ApproverRefusal extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        string $message = '',
        public readonly ?int $minutes = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

<?php

namespace App\Services\Idempotency;

/** What a protected operation reports back to IdempotencyService once it has authoritatively committed. */
final readonly class OperationOutcome
{
    public function __construct(
        public string $resultType,
        public string $resultResourceId,
    ) {}
}

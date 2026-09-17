<?php

namespace App\Services\Idempotency;

/** IdempotencyService::execute()'s return value -- tells the caller whether this was a fresh execution or a replay. */
final readonly class IdempotencyResult
{
    public function __construct(
        public bool $replayed,
        public string $resultType,
        public string $resultResourceId,
    ) {}
}

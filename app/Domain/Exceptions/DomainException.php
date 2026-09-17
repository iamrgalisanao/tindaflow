<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Base of the domain exception hierarchy (error-catalog.md). Every
 * concrete subclass maps to exactly one stable, frozen `code`/HTTP
 * status pair -- controllers/exception handlers render `toErrorEnvelope()`
 * directly, never a raw PostgreSQL/framework exception message.
 */
abstract class DomainException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, protected readonly array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** error-catalog.md's standard envelope shape, minus request_id (attached by the HTTP layer). */
    public function toErrorEnvelope(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
                'details' => $this->details,
            ],
        ];
    }
}

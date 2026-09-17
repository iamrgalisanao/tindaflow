<?php

namespace App\Domain\Exceptions;

/**
 * architecture.md §24 / error-catalog.md CONCURRENCY_CONFLICT -- a
 * generic current-state race lost against another request, used only
 * where no more specific code above applies. Also the target a
 * DatabaseExceptionTranslator maps an unexpected constraint violation
 * to, when the violated constraint doesn't correspond to a named
 * domain exception.
 */
final class ConcurrencyConflictException extends DomainException
{
    public static function becauseStateChanged(string $resource, string $reason): self
    {
        return new self("This {$resource} changed state before this operation could complete: {$reason}", [
            'resource' => $resource,
            'reason' => $reason,
        ]);
    }

    public function errorCode(): string
    {
        return 'CONCURRENCY_CONFLICT';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

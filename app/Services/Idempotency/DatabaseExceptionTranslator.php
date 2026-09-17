<?php

namespace App\Services\Idempotency;

use App\Domain\Exceptions\ConcurrencyConflictException;
use App\Domain\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translates expected PostgreSQL constraint-violation races into stable
 * domain/API errors (Stage 6A instruction §10). A constraint name,
 * SQLSTATE code, or raw driver message must never reach a normal API
 * client -- those are preserved in the application log only.
 *
 * A registry of known constraint-name substrings maps to a specific
 * DomainException factory where one is already known; anything else
 * recognized as a genuine constraint violation falls back to
 * ConcurrencyConflictException, which is honest (a constraint firing
 * under concurrent access almost always means "the state you assumed
 * changed under you") without inventing a specific meaning Stage 6A
 * doesn't yet have enough context to assign correctly.
 */
final class DatabaseExceptionTranslator
{
    private const UNIQUE_VIOLATION = '23505';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const CHECK_VIOLATION = '23514';

    /** @var array<string, callable(string): DomainException> */
    private array $constraintMap = [];

    /** Register a specific constraint-name substring -> exception factory, for Stage 6B+ to extend as new constraints become relevant to a service. */
    public function registerConstraint(string $constraintNameSubstring, callable $factory): void
    {
        $this->constraintMap[$constraintNameSubstring] = $factory;
    }

    /**
     * @throws DomainException when $e is a recognized constraint violation
     * @throws Throwable the original exception, unmodified, when it isn't
     */
    public function translate(Throwable $e): never
    {
        if (! $e instanceof QueryException) {
            throw $e;
        }

        $sqlState = $this->sqlState($e);
        $rawMessage = $e->getMessage();

        Log::warning('Database constraint violation translated to a domain exception', [
            'sql_state' => $sqlState,
            'message' => $rawMessage,
        ]);

        foreach ($this->constraintMap as $needle => $factory) {
            if (str_contains($rawMessage, $needle)) {
                throw $factory($needle);
            }
        }

        throw match ($sqlState) {
            self::UNIQUE_VIOLATION, self::FOREIGN_KEY_VIOLATION, self::CHECK_VIOLATION => ConcurrencyConflictException::becauseStateChanged(
                resource: 'record',
                reason: 'a concurrent operation changed the underlying data before this request could complete'
            ),
            default => $e,
        };
    }

    private function sqlState(QueryException $e): ?string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_string($previous->getCode())) {
            return $previous->getCode();
        }

        return is_string($e->getCode()) ? $e->getCode() : null;
    }
}

<?php

namespace App\Services\Database;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Recognises a database operation that PostgreSQL aborted because it lost a race, not because anything was wrong with
 * the request: a deadlock (SQLSTATE 40P01) or a serialization failure (40001). The whole transaction was rolled back,
 * so retrying the request is safe, and every write that matters carries an Idempotency-Key besides.
 *
 * The SQLSTATE is checked first because it is language-independent; Laravel's own detector matches the English message
 * ("deadlock detected"), which a server running another `lc_messages` would not produce, so it is only the fallback.
 * docs/06-backend/stage-27-deadlock-handling.md.
 */
final class ConcurrencyFailure
{
    /** PostgreSQL class 40 (transaction rollback): deadlock_detected and serialization_failure. */
    private const RETRYABLE_SQLSTATES = ['40P01', '40001'];

    public static function caused(Throwable $e): bool
    {
        // Thrown by Laravel itself when a deadlock happens inside a nested transaction, where it cannot retry.
        if ($e instanceof DeadlockException) {
            return true;
        }

        if ($e instanceof QueryException || $e instanceof PDOException) {
            if (in_array(self::sqlState($e), self::RETRYABLE_SQLSTATES, true)) {
                return true;
            }

            return app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e);
        }

        return false;
    }

    public static function sqlState(Throwable $e): ?string
    {
        $pdo = $e instanceof PDOException ? $e : $e->getPrevious();

        if ($pdo instanceof PDOException && isset($pdo->errorInfo[0]) && is_string($pdo->errorInfo[0])) {
            return $pdo->errorInfo[0];
        }

        return is_string($e->getCode()) ? $e->getCode() : null;
    }
}

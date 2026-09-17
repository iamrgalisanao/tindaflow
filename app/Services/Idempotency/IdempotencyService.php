<?php

namespace App\Services\Idempotency;

use App\Domain\Exceptions\ConcurrencyConflictException;
use App\Domain\Exceptions\IdempotencyKeyReusedException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Implements ADR-010's behavior matrix, generalized to all 14 mandated
 * idempotent operations (Stage 5 instruction §38-41; database-schema.md
 * §14). The reservation row is inserted as the FIRST statement of the
 * SAME transaction as the protected operation -- never a separate,
 * earlier transaction -- so a rolled-back attempt removes its own
 * reservation for free, and a crash before commit leaves no trace at
 * all. See docs/06-backend/stage-6a-transaction-foundation.md for the
 * full scenario walkthrough this design is built to satisfy.
 *
 * Concurrent duplicate submissions are resolved by PostgreSQL itself:
 * two simultaneous INSERTs under the same (terminal_id, idempotency_key)
 * cannot both succeed (UNIQUE constraint); the second blocks until the
 * first resolves, then either fails with a unique-violation (first
 * committed -- re-query and replay its result) or succeeds normally
 * (first rolled back -- no conflict actually existed). This class
 * implements exactly that recovery path; it relies on PostgreSQL's
 * blocking behavior for the actual race-breaking, not on any
 * application-level lock.
 */
final class IdempotencyService
{
    private const TABLE = 'idempotency_records';

    /** `request_hash` is CHAR(64) at rest -- a genuine SHA-256 hex digest, and nothing else, is ever a valid value. */
    private const REQUEST_HASH_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param  Closure(): OperationOutcome  $operation  runs inside the same transaction as the
     *                                                  reservation; its business mutation and the
     *                                                  reservation's completion commit atomically together.
     *
     * @throws InvalidArgumentException $requestHash is not a 64-character lowercase hex digest
     * @throws IdempotencyKeyReusedException same (terminal, key) with a different request hash
     * @throws ConcurrencyConflictException an unexpected race that isn't a same-key replay
     */
    public function execute(
        string $terminalId,
        string $idempotencyKey,
        IdempotencyOperationType $operationType,
        string $requestHash,
        Closure $operation,
    ): IdempotencyResult {
        // Enforce the 64-character SHA-256 hex invariant here, at the
        // point of use, rather than depending on the CHAR(64) column's
        // read-time padding/trim behavior to tolerate a malformed
        // shorter value -- production code (CanonicalRequestHasher's
        // own output) always satisfies this; a caller passing anything
        // else is a bug to surface immediately, not paper over.
        if (! preg_match(self::REQUEST_HASH_PATTERN, $requestHash)) {
            throw new InvalidArgumentException(
                "request_hash must be a 64-character lowercase hex SHA-256 digest, got \"{$requestHash}\" (".
                strlen($requestHash).' characters).'
            );
        }

        $replay = $this->findCompletedReplay($terminalId, $idempotencyKey, $requestHash);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($terminalId, $idempotencyKey, $operationType, $requestHash, $operation) {
                DB::table(self::TABLE)->insert([
                    'terminal_id' => $terminalId,
                    'idempotency_key' => $idempotencyKey,
                    'operation_type' => $operationType->value,
                    'request_hash' => $requestHash,
                    'status' => 'IN_PROGRESS',
                    'created_at' => now(),
                ]);

                $outcome = $operation();

                DB::table(self::TABLE)
                    ->where('terminal_id', $terminalId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->update([
                        'status' => 'COMPLETED',
                        'result_type' => $outcome->resultType,
                        'result_resource_id' => $outcome->resultResourceId,
                        'completed_at' => now(),
                    ]);

                return new IdempotencyResult(replayed: false, resultType: $outcome->resultType, resultResourceId: $outcome->resultResourceId);
            });
        } catch (QueryException $e) {
            if (! $this->isReservationUniqueViolation($e)) {
                throw $e;
            }

            // We lost the race to insert the reservation -- by the time
            // our own (now-rolled-back) transaction's INSERT was allowed
            // to proceed and fail, the winner's row must be visible.
            // Re-query and treat it exactly like a same-key replay.
            $winner = $this->findCompletedReplay($terminalId, $idempotencyKey, $requestHash);
            if ($winner !== null) {
                return $winner;
            }

            throw ConcurrencyConflictException::becauseStateChanged(
                resource: 'idempotency_record',
                reason: 'a concurrent request for this key is still in progress'
            );
        }
    }

    private function findCompletedReplay(string $terminalId, string $idempotencyKey, string $requestHash): ?IdempotencyResult
    {
        $existing = DB::table(self::TABLE)
            ->where('terminal_id', $terminalId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'COMPLETED')
            ->first();

        if ($existing === null) {
            return null;
        }

        // request_hash is CHAR(64); PostgreSQL only right-pads a value
        // SHORTER than the declared length, and execute() above already
        // rejects any $requestHash that isn't exactly 64 characters
        // before it can ever be persisted -- so $existing->request_hash
        // is guaranteed to come back at its full, unpadded length here.
        // No trim is needed, or applied.
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw IdempotencyKeyReusedException::forKey($terminalId, $idempotencyKey);
        }

        return new IdempotencyResult(replayed: true, resultType: $existing->result_type, resultResourceId: $existing->result_resource_id);
    }

    private function isReservationUniqueViolation(QueryException $e): bool
    {
        $previous = $e->getPrevious();
        $sqlState = $previous instanceof \PDOException ? $previous->getCode() : $e->getCode();

        return $sqlState === '23505' && str_contains($e->getMessage(), 'idempotency_records');
    }
}

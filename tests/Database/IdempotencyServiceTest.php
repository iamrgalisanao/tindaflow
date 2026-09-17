<?php

namespace Tests\Database;

use App\Domain\Exceptions\IdempotencyKeyReusedException;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage 6A: live integration tests for IdempotencyService, proving the
 * transaction-coupled reservation/replay design against real
 * PostgreSQL, not a mock. Reuses Stage 5's PostgresSchemaTestCase
 * infrastructure since this genuinely depends on database transactional
 * behavior (rollback, unique-constraint blocking) a mock cannot exercise.
 */
class IdempotencyServiceTest extends PostgresSchemaTestCase
{
    private IdempotencyService $service;

    private string $terminalId;

    private string $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IdempotencyService;

        $this->storeId = (string) Str::uuid();
        $this->terminalId = (string) Str::uuid();

        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('terminals')->insert([
            'id' => $this->terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'T-01',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Owner hardening pass: production code should never be able to
     * persist a short/malformed hash -- IdempotencyService enforces the
     * 64-character SHA-256 hex invariant itself rather than depending
     * on CHAR(64)'s read-time behavior to tolerate one.
     */
    public function test_rejects_a_request_hash_that_is_not_a_64_character_hex_digest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            $this->terminalId,
            (string) Str::uuid(),
            IdempotencyOperationType::Checkout,
            'not-a-real-hash',
            fn () => new OperationOutcome('sale', (string) Str::uuid())
        );
    }

    public function test_new_key_executes_the_operation(): void
    {
        $called = 0;
        $key = (string) Str::uuid();
        $saleId = (string) Str::uuid();

        $result = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), function () use (&$called, $saleId) {
            $called++;

            return new OperationOutcome('sale', $saleId);
        });

        $this->assertSame(1, $called);
        $this->assertFalse($result->replayed);
        $this->assertSame($saleId, $result->resultResourceId);
    }

    public function test_same_key_same_hash_replays_without_reexecuting(): void
    {
        $called = 0;
        $key = (string) Str::uuid();
        $saleId = (string) Str::uuid();
        $operation = function () use (&$called, $saleId) {
            $called++;

            return new OperationOutcome('sale', $saleId);
        };

        $first = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), $operation);
        $second = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), $operation);

        $this->assertSame(1, $called, 'the operation must run exactly once, not on the replay');
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->resultResourceId, $second->resultResourceId);
    }

    public function test_same_key_different_hash_throws_idempotency_key_reused(): void
    {
        $key = (string) Str::uuid();

        $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), fn () => new OperationOutcome('sale', (string) Str::uuid()));

        $this->expectException(IdempotencyKeyReusedException::class);
        $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('b', 64), fn () => new OperationOutcome('sale', (string) Str::uuid()));
    }

    public function test_failed_operation_does_not_consume_the_key(): void
    {
        $key = (string) Str::uuid();

        try {
            $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), function () {
                throw new RuntimeException('business rule failed before authoritative mutation');
            });
            $this->fail('expected the operation exception to propagate');
        } catch (RuntimeException) {
            // expected
        }

        // No row at all should remain -- the reservation rolled back with everything else.
        $row = DB::table('idempotency_records')
            ->where('terminal_id', $this->terminalId)
            ->where('idempotency_key', $key)
            ->first();
        $this->assertNull($row, 'a failed attempt must leave no idempotency_records row, not a stuck IN_PROGRESS one');

        // The same key must be freely reusable for a genuinely fresh attempt.
        $saleId = (string) Str::uuid();
        $result = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), fn () => new OperationOutcome('sale', $saleId));
        $this->assertFalse($result->replayed);
        $this->assertSame($saleId, $result->resultResourceId);
    }

    public function test_successful_commit_survives_simulated_response_loss(): void
    {
        $key = (string) Str::uuid();
        $saleId = (string) Str::uuid();

        // "Response lost after commit" is simulated by simply calling
        // execute() again with the identical key+hash, as a client would
        // on a network-timeout retry -- the authoritative result must be
        // returned rather than the operation running a second time.
        $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), fn () => new OperationOutcome('sale', $saleId));

        $called = 0;
        $retry = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), function () use (&$called) {
            $called++;

            return new OperationOutcome('sale', (string) Str::uuid());
        });

        $this->assertSame(0, $called);
        $this->assertTrue($retry->replayed);
        $this->assertSame($saleId, $retry->resultResourceId);
    }

    public function test_different_terminals_may_use_the_same_key_independently(): void
    {
        $otherTerminalId = (string) Str::uuid();
        DB::table('terminals')->insert([
            'id' => $otherTerminalId, 'store_id' => $this->storeId,
            'terminal_code' => 'T-02', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sharedKey = (string) Str::uuid();
        $saleId1 = (string) Str::uuid();
        $saleId2 = (string) Str::uuid();

        $first = $this->service->execute($this->terminalId, $sharedKey, IdempotencyOperationType::Checkout, str_repeat('a', 64), fn () => new OperationOutcome('sale', $saleId1));
        $second = $this->service->execute($otherTerminalId, $sharedKey, IdempotencyOperationType::Checkout, str_repeat('a', 64), fn () => new OperationOutcome('sale', $saleId2));

        $this->assertFalse($first->replayed);
        $this->assertFalse($second->replayed, 'a different terminal presenting the same UUID key is a completely separate namespace, not a replay');
        $this->assertSame($saleId1, $first->resultResourceId);
        $this->assertSame($saleId2, $second->resultResourceId);
    }

    public function test_concurrent_duplicate_insert_is_resolved_by_the_unique_constraint(): void
    {
        // Simulates "the race already resolved in the other request's
        // favor by the time we check" -- a completed row already exists
        // when we call execute(), which must be treated as a replay
        // rather than attempting (and failing) a duplicate INSERT.
        // True simultaneous-process concurrency (two live connections
        // blocking on the same row lock) is documented as a Stage 8
        // concern in stage-6a-transaction-foundation.md, not exercised
        // here -- this proves the recovery logic, not the OS-level race.
        $key = (string) Str::uuid();
        $winnerSaleId = (string) Str::uuid();
        DB::table('idempotency_records')->insert([
            'terminal_id' => $this->terminalId,
            'idempotency_key' => $key,
            'operation_type' => IdempotencyOperationType::Checkout->value,
            'request_hash' => str_repeat('a', 64),
            'status' => 'COMPLETED',
            'result_type' => 'sale',
            'result_resource_id' => $winnerSaleId,
            'created_at' => now(),
            'completed_at' => now(),
        ]);

        $called = 0;
        $result = $this->service->execute($this->terminalId, $key, IdempotencyOperationType::Checkout, str_repeat('a', 64), function () use (&$called) {
            $called++;

            return new OperationOutcome('sale', (string) Str::uuid());
        });

        $this->assertSame(0, $called);
        $this->assertTrue($result->replayed);
        $this->assertSame($winnerSaleId, $result->resultResourceId);
    }
}

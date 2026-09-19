<?php

namespace Tests\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 6A hardening (owner review): proves the Stage 6A acceptance
 * criterion the sequential tests in IdempotencyServiceTest cannot --
 * "simultaneous duplicate requests produce one authoritative mutation"
 * -- using two INDEPENDENT OS processes, each with its own PostgreSQL
 * connection, synchronized at a file-based barrier so both attempt
 * IdempotencyService::execute() at approximately the same instant.
 *
 * This intentionally does NOT extend PostgresSchemaTestCase: that base
 * wraps every test in an uncommitted transaction rolled back in
 * tearDown, which would make this test's fixtures invisible to the
 * separate worker processes (different connections, different
 * transactions -- MVCC visibility rules). Fixtures here are committed
 * normally and cleaned up explicitly.
 *
 * PostgreSQL behavior this test relies on and verifies (documented per
 * the owner's request, not just asserted):
 *   - UNIQUE constraint on (terminal_id, idempotency_key): when two
 *     transactions concurrently INSERT conflicting rows, PostgreSQL
 *     blocks the second inserter until the first's transaction
 *     resolves (commit or rollback) -- it does not immediately error.
 *   - Transaction visibility (READ COMMITTED, Postgres's default): a
 *     concurrent transaction cannot see another's uncommitted INSERT at
 *     all, so IdempotencyService's fast-path "is there already a
 *     COMPLETED row?" check never observes a competitor's in-flight
 *     attempt -- both workers legitimately proceed to the INSERT step.
 *   - Blocking/retry behavior: if the first transaction commits, the
 *     second's blocked INSERT then raises a unique-violation
 *     (SQLSTATE 23505); if the first rolls back, the second's blocked
 *     INSERT succeeds normally, as if no race had happened at all.
 *   - Exception translation: IdempotencyService::execute() catches
 *     exactly that unique-violation QueryException and re-queries for
 *     the now-committed winner's row rather than surfacing a raw
 *     database error to the loser.
 */
class IdempotencyConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $storeId;

    private string $terminalId;

    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        // The mutation-tracking table is test-only infrastructure, not
        // part of the frozen schema -- it exists solely so this test can
        // count "how many times did the business callback truly run?"
        // from outside any single process's memory.
        DB::statement('CREATE TABLE IF NOT EXISTS idempotency_race_mutations (id uuid PRIMARY KEY, marker text NOT NULL, created_at timestamptz NOT NULL)');
        DB::table('idempotency_race_mutations')->truncate();
        DB::table('idempotency_records')->truncate();

        $this->storeId = (string) Str::uuid();
        $this->terminalId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Race Test Store', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('terminals')->insert([
            'id' => $this->terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'RACE-01',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_race_'.Str::random(8);
        mkdir($this->scratchDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    public function test_simultaneous_same_key_same_hash_requests_produce_exactly_one_mutation(): void
    {
        $key = (string) Str::uuid();
        $hash = str_repeat('a', 64);
        $marker = 'same-hash-race';

        [$resultA, $resultB] = $this->race($key, $hash, $hash, $marker);

        // 1. Business mutation callback executed exactly once.
        $mutationCount = DB::table('idempotency_race_mutations')->where('marker', $marker)->count();
        $this->assertSame(1, $mutationCount, 'the business mutation must have executed exactly once, not twice');

        // 2. Exactly one authoritative business result / completed idempotency record.
        $record = DB::table('idempotency_records')
            ->where('terminal_id', $this->terminalId)->where('idempotency_key', $key)->get();
        $this->assertCount(1, $record, 'exactly one idempotency_records row must exist for this key');
        $this->assertSame('COMPLETED', $record->first()->status);

        // 3. Neither worker errored.
        $this->assertSame('success', $resultA['outcome'], json_encode($resultA));
        $this->assertSame('success', $resultB['outcome'], json_encode($resultB));

        // 4. Both workers observed the SAME authoritative result.
        $this->assertSame($resultA['result_resource_id'], $resultB['result_resource_id']);
        $this->assertSame($record->first()->result_resource_id, $resultA['result_resource_id']);

        // 5. Exactly one of the two was the "real" execution; the other replayed.
        $replayedFlags = [$resultA['replayed'], $resultB['replayed']];
        sort($replayedFlags);
        $this->assertSame([false, true], $replayedFlags, 'exactly one worker must be the fresh execution and the other a replay');
    }

    public function test_simultaneous_same_key_conflicting_hash_resolves_deterministically(): void
    {
        $key = (string) Str::uuid();
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);
        $marker = 'conflicting-hash-race';

        [$resultA, $resultB] = $this->race($key, $hashA, $hashB, $marker);

        // Exactly one business mutation, regardless of which side won.
        $mutationCount = DB::table('idempotency_race_mutations')->where('marker', $marker)->count();
        $this->assertSame(1, $mutationCount, 'only the winning hash may execute the business mutation');

        $record = DB::table('idempotency_records')->where('terminal_id', $this->terminalId)->where('idempotency_key', $key)->get();
        $this->assertCount(1, $record);
        $this->assertSame('COMPLETED', $record->first()->status);

        // Exactly one worker succeeded, exactly one hit IDEMPOTENCY_KEY_REUSED --
        // we don't control (or care) which hash wins the race.
        $outcomes = [$resultA['outcome'], $resultB['outcome']];
        sort($outcomes);
        $this->assertSame(['exception', 'success'], $outcomes);

        $winner = $resultA['outcome'] === 'success' ? $resultA : $resultB;
        $loser = $resultA['outcome'] === 'success' ? $resultB : $resultA;

        $this->assertFalse($winner['replayed'], 'the winner is a genuinely fresh execution, never a replay of a conflicting hash');
        $this->assertSame('App\\Domain\\Exceptions\\IdempotencyKeyReusedException', $loser['class']);

        $winningHash = $winner === $resultA ? $hashA : $hashB;
        $this->assertSame(rtrim($record->first()->request_hash), $winningHash);
    }

    public function test_different_terminals_same_uuid_key_execute_independently_even_when_simultaneous(): void
    {
        $otherTerminalId = (string) Str::uuid();
        DB::table('terminals')->insert([
            'id' => $otherTerminalId, 'store_id' => $this->storeId, 'terminal_code' => 'RACE-02',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sharedKey = (string) Str::uuid();
        $hash = str_repeat('c', 64);
        $marker = 'cross-terminal-race';

        [$resultA, $resultB] = $this->race($sharedKey, $hash, $hash, $marker, terminalB: $otherTerminalId);

        $mutationCount = DB::table('idempotency_race_mutations')->where('marker', $marker)->count();
        $this->assertSame(2, $mutationCount, 'two different terminals sharing a UUID key are a completely separate namespace -- both must execute');

        $this->assertSame('success', $resultA['outcome']);
        $this->assertSame('success', $resultB['outcome']);
        $this->assertFalse($resultA['replayed']);
        $this->assertFalse($resultB['replayed']);
        $this->assertNotSame($resultA['result_resource_id'], $resultB['result_resource_id']);
    }

    /** @return array{0: array, 1: array} decoded JSON output from worker A and worker B */
    private function race(string $key, string $hashA, string $hashB, string $marker, ?string $terminalB = null): array
    {
        $workerScript = base_path('tests/Database/support/idempotency_race_worker.php');
        $readyA = $this->scratchDir.'/ready_a';
        $readyB = $this->scratchDir.'/ready_b';
        $go = $this->scratchDir.'/go';
        $outA = $this->scratchDir.'/out_a.json';
        $outB = $this->scratchDir.'/out_b.json';

        $terminalA = $this->terminalId;
        $terminalB ??= $this->terminalId;

        $processA = Process::start([PHP_BINARY, $workerScript, $terminalA, $key, $hashA, $readyA, $go, $outA, self::DATABASE, $marker]);
        $processB = Process::start([PHP_BINARY, $workerScript, $terminalB, $key, $hashB, $readyB, $go, $outB, self::DATABASE, $marker]);

        $deadline = microtime(true) + 10;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }

        // Release both workers at (as close as two OS processes get to)
        // the same instant.
        file_put_contents($go, '1');

        $resultA = $processA->wait();
        $resultB = $processB->wait();

        $this->assertTrue($resultA->successful(), "worker A process failed: {$resultA->errorOutput()}");
        $this->assertTrue($resultB->successful(), "worker B process failed: {$resultB->errorOutput()}");

        $this->assertFileExists($outA, 'worker A did not write an output file');
        $this->assertFileExists($outB, 'worker B did not write an output file');

        return [
            json_decode(file_get_contents($outA), true),
            json_decode(file_get_contents($outB), true),
        ];
    }
}

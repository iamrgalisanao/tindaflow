<?php

namespace Tests\Database;

use App\Services\Database\ConcurrencyFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 27: proves against a REAL PostgreSQL deadlock (two connections in two OS processes reaching for each other's row)
 * that what the driver raises is what ConcurrencyFailure expects: a Laravel QueryException carrying SQLSTATE 40P01. The
 * unit tests build that exception by hand; this is the check that the hand-built one has the real shape. Like the other
 * concurrency tests it does not extend PostgresSchemaTestCase, because its fixtures must be visible to the worker.
 */
class RealDeadlockClassificationTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

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

        // Test-only table, like idempotency_race_mutations: two rows for the two sides to fight over.
        DB::statement('CREATE TABLE IF NOT EXISTS deadlock_probe (id integer PRIMARY KEY, n integer NOT NULL)');
        DB::table('deadlock_probe')->truncate();
        DB::table('deadlock_probe')->insert([['id' => 1, 'n' => 0], ['id' => 2, 'n' => 0]]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_deadlock_'.Str::random(8);
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

    public function test_a_real_postgresql_deadlock_is_recognised_as_a_lost_race(): void
    {
        $ready = $this->scratchDir.'/ready';
        $go = $this->scratchDir.'/go';
        $out = $this->scratchDir.'/out.json';

        $worker = Process::start([PHP_BINARY, base_path('tests/Database/support/deadlock_probe_worker.php'), $ready, $go, $out, self::DATABASE]);

        // This process holds row 2 and, once the worker holds row 1, reaches for row 1 while the worker reaches for row 2.
        DB::beginTransaction();
        DB::table('deadlock_probe')->where('id', 2)->update(['n' => DB::raw('n + 1')]);

        $deadline = microtime(true) + 15;
        while (! file_exists($ready)) {
            if (microtime(true) > $deadline) {
                DB::rollBack();
                $this->fail('the worker did not take its lock in time');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $mine = null;
        try {
            DB::table('deadlock_probe')->where('id', 1)->update(['n' => DB::raw('n + 1')]);
            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            $mine = $e;
        }

        $result = $worker->wait();
        $this->assertTrue($result->successful(), "worker failed: {$result->errorOutput()}");
        $theirs = json_decode(file_get_contents($out), true);

        // PostgreSQL aborts exactly one side of the cycle; which one is its choice ("difficult to predict").
        $this->assertTrue(($mine !== null) xor ($theirs['outcome'] === 'aborted'), 'exactly one of the two must have been the deadlock victim: '.json_encode($theirs));

        if ($mine !== null) {
            $this->assertSame('40P01', ConcurrencyFailure::sqlState($mine));
            $this->assertTrue(ConcurrencyFailure::caused($mine), 'the application must recognise the real deadlock error');
            $this->assertSame('success', $theirs['outcome']);
        } else {
            $this->assertSame('QueryException', $theirs['class']);
            $this->assertSame('40P01', $theirs['sql_state']);
            $this->assertTrue($theirs['recognised'], 'the application must recognise the real deadlock error');
        }
    }
}

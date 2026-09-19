<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves invariants.md #32/#33/#34 (at most one OPEN fiscal_day per
 * terminal; at most one OPEN shift per terminal; at most one OPEN shift
 * per cashier) hold under genuine concurrency, not merely the
 * SELECT ... FOR UPDATE reasoning in ShiftOpenService -- same two-real-
 * OS-process technique as every other concurrency test in this project.
 * Does not extend PostgresSchemaTestCase for the same reason as
 * CheckoutServiceConcurrencyTest: fixtures must be visible to separate
 * worker processes/connections, which a rolled-back transaction would hide.
 */
class ShiftOpenConcurrencyTest extends TestCase
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
        } else {
            foreach (['idempotency_records', 'shifts', 'fiscal_days', 'terminals', 'users', 'stores'] as $table) {
                DB::table($table)->delete();
            }
        }

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_shift_open_race_'.Str::random(8);
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

    public function test_two_cashiers_racing_to_open_a_shift_on_the_same_terminal_produce_exactly_one_shift(): void
    {
        $store = Store::factory()->create();
        $terminal = Terminal::factory()->create(['store_id' => $store->id]);
        $cashierA = User::factory()->create(['store_id' => $store->id]);
        $cashierB = User::factory()->create(['store_id' => $store->id]);

        [$resultA, $resultB] = $this->race(
            $terminal->id, $cashierA->id, (string) Str::uuid(),
            $terminal->id, $cashierB->id, (string) Str::uuid(),
        );

        $outcomes = [$resultA['outcome'], $resultB['outcome']];
        sort($outcomes);
        $this->assertSame(['exception', 'success'], $outcomes, json_encode([$resultA, $resultB]));

        $loser = $resultA['outcome'] === 'exception' ? $resultA : $resultB;
        $this->assertSame('SHIFT_ALREADY_OPEN', $loser['error_code']);

        $this->assertSame(1, Shift::where('terminal_id', $terminal->id)->count());
        $this->assertSame(1, FiscalDay::where('terminal_id', $terminal->id)->count(), 'exactly one fiscal_day, never two');
    }

    public function test_one_cashier_racing_to_open_a_shift_on_two_terminals_produces_exactly_one_shift(): void
    {
        $store = Store::factory()->create();
        $terminalA = Terminal::factory()->create(['store_id' => $store->id]);
        $terminalB = Terminal::factory()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);

        [$resultA, $resultB] = $this->race(
            $terminalA->id, $cashier->id, (string) Str::uuid(),
            $terminalB->id, $cashier->id, (string) Str::uuid(),
        );

        $outcomes = [$resultA['outcome'], $resultB['outcome']];
        sort($outcomes);
        $this->assertSame(['exception', 'success'], $outcomes, json_encode([$resultA, $resultB]));

        $loser = $resultA['outcome'] === 'exception' ? $resultA : $resultB;
        $this->assertSame('SHIFT_ALREADY_OPEN', $loser['error_code']);

        $this->assertSame(1, Shift::where('cashier_id', $cashier->id)->count(), 'invariant #34: at most one open shift per cashier, across terminals');
    }

    /** @return array{0: array, 1: array} decoded JSON output from worker A and worker B */
    private function race(string $terminalIdA, string $cashierIdA, string $keyA, string $terminalIdB, string $cashierIdB, string $keyB): array
    {
        $workerScript = base_path('tests/Database/support/shift_open_race_worker.php');
        $readyA = $this->scratchDir.'/ready_a';
        $readyB = $this->scratchDir.'/ready_b';
        $go = $this->scratchDir.'/go';
        $outA = $this->scratchDir.'/out_a.json';
        $outB = $this->scratchDir.'/out_b.json';

        $processA = Process::start([PHP_BINARY, $workerScript, $terminalIdA, $cashierIdA, $keyA, '500.00', $readyA, $go, $outA, self::DATABASE]);
        $processB = Process::start([PHP_BINARY, $workerScript, $terminalIdB, $cashierIdB, $keyB, '500.00', $readyB, $go, $outB, self::DATABASE]);

        $deadline = microtime(true) + 10;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }

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

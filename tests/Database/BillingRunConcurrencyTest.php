<?php

namespace Tests\Database;

use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-016 slice 4.1, BILL-005: billing run numbers are gap-free and never issued twice, even when saves race. Real OS processes
 * with their own connections are released together (like the other concurrency tests, this does not extend
 * PostgresSchemaTestCase, so its fixtures are committed and visible to the workers).
 */
class BillingRunConcurrencyTest extends TestCase
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

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_billing_race_'.Str::random(8);
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

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/billing_run_race_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            file_put_contents($this->scratchDir."/job_{$index}.json", json_encode($job));
            $processes[$index] = Process::start([
                PHP_BINARY, $script, $this->scratchDir."/job_{$index}.json", $this->scratchDir."/ready_{$index}",
                $go, $this->scratchDir."/out_{$index}.json", self::DATABASE,
            ]);
        }

        $deadline = microtime(true) + 20;
        while (count(glob($this->scratchDir.'/ready_*') ?: []) < count($jobs)) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $outcomes = [];
        foreach ($processes as $index => $process) {
            $result = $process->wait();
            $this->assertTrue($result->successful(), "worker {$index} failed: {$result->errorOutput()}");
            $outcomes[] = json_decode(file_get_contents($this->scratchDir."/out_{$index}.json"), true);
        }

        return $outcomes;
    }

    public function test_racing_saves_get_distinct_gap_free_run_numbers(): void
    {
        $storeId = Terminal::factory()->create()->store_id;
        $manager = User::factory()->manager()->create(['store_id' => $storeId]);

        $outcomes = $this->race([
            ['user_id' => $manager->id, 'count' => 4, 'from' => '2026-10-01', 'to' => '2026-10-15'],
            ['user_id' => $manager->id, 'count' => 4, 'from' => '2026-10-01', 'to' => '2026-10-15'],
            ['user_id' => $manager->id, 'count' => 4, 'from' => '2026-10-16', 'to' => '2026-10-31'],
        ]);

        $this->assertSame(['success', 'success', 'success'], array_column($outcomes, 'outcome'), json_encode($outcomes));

        $stored = DB::table('billing_runs')->where('store_id', $storeId)->orderBy('sequence')->pluck('run_number')->all();
        $this->assertSame(array_map(fn ($n) => sprintf('BR-%06d', $n), range(1, 12)), $stored);
        $returned = collect($outcomes)->pluck('numbers')->flatten()->sort()->values()->all();
        $this->assertSame($stored, $returned, 'every number handed out is a number stored, once');
        $this->assertSame(12, (int) DB::table('billing_run_counters')->where('store_id', $storeId)->value('last_number'));
    }
}

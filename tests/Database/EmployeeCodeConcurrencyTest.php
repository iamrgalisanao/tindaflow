<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-016 slice 3.1, EMP-001: employee codes are gap-free and never issued twice, even when creations race. Real OS processes
 * with their own connections are released together (like the other concurrency tests, this does not extend
 * PostgresSchemaTestCase, so its fixtures are committed and visible to the workers).
 */
class EmployeeCodeConcurrencyTest extends TestCase
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

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_employee_race_'.Str::random(8);
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
        $script = base_path('tests/Database/support/employee_create_race_worker.php');
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

    public function test_racing_creations_get_distinct_gap_free_codes_per_type(): void
    {
        $storeId = Terminal::factory()->create()->store_id;
        $admin = User::factory()->admin()->create(['store_id' => $storeId]);

        $outcomes = $this->race([
            ['user_id' => $admin->id, 'employee_type' => 'DIRECT', 'count' => 5, 'prefix' => 'A'],
            ['user_id' => $admin->id, 'employee_type' => 'DIRECT', 'count' => 5, 'prefix' => 'B'],
            ['user_id' => $admin->id, 'employee_type' => 'SUBCONTRACTOR', 'count' => 4, 'prefix' => 'C'],
            ['user_id' => $admin->id, 'employee_type' => 'SUBCONTRACTOR', 'count' => 4, 'prefix' => 'D'],
        ]);

        $this->assertSame(['success', 'success', 'success', 'success'], array_column($outcomes, 'outcome'), json_encode($outcomes));

        $direct = Employee::where('store_id', $storeId)->where('employee_type', 'DIRECT')->pluck('employee_code')->sort()->values()->all();
        $subs = Employee::where('store_id', $storeId)->where('employee_type', 'SUBCONTRACTOR')->pluck('employee_code')->sort()->values()->all();

        $this->assertSame(array_map(fn ($n) => sprintf('TSC%05d', $n), range(1, 10)), $direct);
        $this->assertSame(array_map(fn ($n) => sprintf('SUB%05d', $n), range(1, 8)), $subs);
        $this->assertSame(10, (int) DB::table('employee_code_sequences')->where('store_id', $storeId)->where('employee_type', 'DIRECT')->value('last_number'));
    }
}

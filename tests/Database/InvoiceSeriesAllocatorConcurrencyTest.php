<?php

namespace Tests\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 6B -- proves InvoiceSeriesAllocator's true multi-process
 * concurrency behavior against real PostgreSQL 17, mirroring the
 * pattern Stage 6A established in IdempotencyConcurrencyTest: genuinely
 * independent OS processes (Process::start()), each with its own
 * connection, synchronized at a file-based barrier so every worker
 * attempts SELECT ... FOR UPDATE at approximately the same instant.
 *
 * Owner review, 2026-09-17 (second pass): re-run after two amendments --
 * (1) a fresh series bootstraps at `starting_number - 1`, so the
 * expected contiguous range now begins at the configured
 * `starting_number` itself, not one past it; (2) resolution is keyed by
 * `(store_id, fiscal_installation_id)`, so "different series" fixtures
 * now use two `fiscal_installation`s under one store (DB-INV-018), the
 * actual frozen-model shape, rather than two separate stores.
 *
 * Does NOT extend PostgresSchemaTestCase for the same reason
 * IdempotencyConcurrencyTest doesn't: that base wraps each test in an
 * uncommitted transaction rolled back in tearDown, which would make
 * this test's fixtures invisible to the separate worker processes
 * (different connections, different transactions -- MVCC visibility
 * rules). Fixtures here are committed normally and truncated per test.
 */
class InvoiceSeriesAllocatorConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $storeId;

    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => self::DATABASE,
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => '',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        DB::table('invoices')->truncate();
        DB::table('invoice_series')->truncate();
        DB::table('fiscal_installations')->truncate();
        DB::table('stores')->truncate();

        $this->storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Concurrency Test Store', 'created_at' => now(), 'updated_at' => now()]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_invseries_'.Str::random(8);
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

    private function insertFiscalInstallation(string $storeId): string
    {
        $id = (string) Str::uuid();

        DB::table('fiscal_installations')->insert([
            'id' => $id, 'store_id' => $storeId, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0', 'installed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertSeries(string $fiscalInstallationId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('invoice_series')->insert(array_merge([
            'id' => $id,
            'store_id' => $this->storeId,
            'fiscal_installation_id' => $fiscalInstallationId,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            // Counter semantics (invariants.md INVSERIES-001): a fresh
            // series bootstraps at starting_number - 1, so its first
            // real allocation yields starting_number exactly.
            'current_number' => 0,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * Owner instruction §15: run the stress scenario multiple times in
     * one suite execution to surface flaky race behavior, not just once.
     */
    public function test_ten_workers_against_one_series_produce_unique_contiguous_serials(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            DB::table('invoice_series')->truncate();
            $installationId = $this->insertFiscalInstallation($this->storeId);
            $seriesId = $this->insertSeries($installationId);

            $results = $this->raceWorkers(10, storeIds: array_fill(0, 10, $this->storeId), fiscalInstallationIds: array_fill(0, 10, $installationId));

            $committed = array_filter($results, fn ($r) => $r['outcome'] === 'committed');
            $this->assertCount(10, $committed, "iteration {$iteration}: all 10 workers must commit successfully -- got: ".json_encode($results));

            $serials = array_map(fn ($r) => $r['serial'], $committed);
            sort($serials);
            $this->assertSame(range(1, 10), $serials, "iteration {$iteration}: committed serials must be the exact contiguous set 1..10 (the configured starting_number, never skipped), no duplicates, no gaps");

            $seriesIds = array_unique(array_map(fn ($r) => $r['invoice_series_id'], $committed));
            $this->assertSame([$seriesId], $seriesIds, "iteration {$iteration}: every worker must have allocated from the single series under test");

            $this->assertSame(10, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'), "iteration {$iteration}: final counter must reflect exactly 10 committed allocations");
        }
    }

    public function test_concurrent_different_fiscal_installations_of_the_same_store_allocate_independently(): void
    {
        // DB-INV-018 / invariants.md INVSERIES-002: one store may
        // legitimately have more than one invoice_series, through more
        // than one fiscal_installation -- this is the actual frozen-model
        // shape, not two separate stores. Resolution is keyed by
        // (store_id, fiscal_installation_id), so both series are
        // simultaneously ACTIVE under the SAME store without any
        // InvoiceSeriesResolutionException.
        $installationA = $this->insertFiscalInstallation($this->storeId);
        $installationB = $this->insertFiscalInstallation($this->storeId);

        $seriesA = $this->insertSeries($installationA, ['series_code' => 'A']);
        $seriesB = $this->insertSeries($installationB, ['series_code' => 'B', 'current_number' => 49, 'starting_number' => 50]);

        // Interleave workers for A and B in one barrier release, so both
        // series are genuinely contended for at the same instant, not
        // run as two sequential single-series batches.
        $storeIds = array_fill(0, 10, $this->storeId);
        $installationIds = array_merge(array_fill(0, 5, $installationA), array_fill(0, 5, $installationB));
        $results = $this->raceWorkers(10, storeIds: $storeIds, fiscalInstallationIds: $installationIds);

        $committed = array_filter($results, fn ($r) => $r['outcome'] === 'committed');
        $this->assertCount(10, $committed, 'all 10 workers (5 per installation) must commit successfully, with no InvoiceSeriesResolutionException merely because the store has two active series: '.json_encode($results));

        $forA = array_values(array_filter($committed, fn ($r) => $r['invoice_series_id'] === $seriesA));
        $forB = array_values(array_filter($committed, fn ($r) => $r['invoice_series_id'] === $seriesB));

        $this->assertCount(5, $forA);
        $this->assertCount(5, $forB);

        $serialsA = array_map(fn ($r) => $r['serial'], $forA);
        sort($serialsA);
        $this->assertSame(range(1, 5), $serialsA, 'installation A (starting_number=1) must allocate 1..5, independent of installation B');

        $serialsB = array_map(fn ($r) => $r['serial'], $forB);
        sort($serialsB);
        $this->assertSame(range(50, 54), $serialsB, 'installation B (starting_number=50) must allocate 50..54, independent of installation A');

        $this->assertSame(5, (int) DB::table('invoice_series')->where('id', $seriesA)->value('current_number'));
        $this->assertSame(54, (int) DB::table('invoice_series')->where('id', $seriesB)->value('current_number'));
    }

    /**
     * Owner instruction §16: Worker A locks the series, allocates, then
     * deliberately rolls back while holding the lock for a moment
     * (sleepMsAfterAllocate) so Worker B's SELECT ... FOR UPDATE
     * genuinely blocks on A's row lock rather than happening to run
     * after A by scheduling luck. After A's rollback releases the lock,
     * B must receive the SAME serial A used, proving A's failed attempt
     * introduced no permanent gap.
     */
    public function test_rollback_under_contention_does_not_skip_a_serial(): void
    {
        $installationId = $this->insertFiscalInstallation($this->storeId);
        $seriesId = $this->insertSeries($installationId, ['current_number' => 40]);

        $workerScript = base_path('tests/Database/support/invoice_series_allocation_worker.php');
        $readyA = $this->scratchDir.'/ready_a';
        $readyB = $this->scratchDir.'/ready_b';
        $go = $this->scratchDir.'/go';
        $outA = $this->scratchDir.'/out_a.json';
        $outB = $this->scratchDir.'/out_b.json';

        $processA = Process::start([PHP_BINARY, $workerScript, $this->storeId, $installationId, $readyA, $go, $outA, self::DATABASE, 'rollback', '400']);
        $processB = Process::start([PHP_BINARY, $workerScript, $this->storeId, $installationId, $readyB, $go, $outB, self::DATABASE, 'commit', '0']);

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

        $outcomeA = json_decode(file_get_contents($outA), true);
        $outcomeB = json_decode(file_get_contents($outB), true);

        $this->assertSame('rolled_back', $outcomeA['outcome'], json_encode($outcomeA));
        $this->assertSame('committed', $outcomeB['outcome'], json_encode($outcomeB));

        // The serial A allocated-then-abandoned (41) must be exactly
        // the serial B receives -- not 42, which would mean A's failed
        // attempt permanently burned a number.
        $this->assertSame(41, $outcomeB['serial'], 'B must receive the serial A rolled back, not the one after it');
        $this->assertSame(41, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'), 'only B\'s single committed allocation may be reflected in the final counter');
    }

    /**
     * @param  array<int, string>  $storeIds
     * @param  array<int, string>  $fiscalInstallationIds  one fiscal_installation_id per worker -- resolution is
     *                                                     keyed by (store_id, fiscal_installation_id), so which
     *                                                     series each worker allocates from is determined by
     *                                                     which installation it's given plus that installation's
     *                                                     single ACTIVE series.
     * @return array<int, array<string, mixed>>
     */
    private function raceWorkers(int $count, array $storeIds, array $fiscalInstallationIds): array
    {
        $workerScript = base_path('tests/Database/support/invoice_series_allocation_worker.php');
        $ready = [];
        $out = [];
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $ready[$i] = $this->scratchDir."/ready_{$i}";
            $out[$i] = $this->scratchDir."/out_{$i}.json";
        }
        $go = $this->scratchDir.'/go';

        for ($i = 0; $i < $count; $i++) {
            $processes[$i] = Process::start([PHP_BINARY, $workerScript, $storeIds[$i], $fiscalInstallationIds[$i], $ready[$i], $go, $out[$i], self::DATABASE, 'commit', '0']);
        }

        $deadline = microtime(true) + 15;
        while (count(array_filter($ready, fn ($f) => file_exists($f))) < $count) {
            if (microtime(true) > $deadline) {
                $this->fail('not all worker processes became ready within the timeout');
            }
            usleep(1000);
        }

        file_put_contents($go, '1');

        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $processes[$i]->wait();
            $this->assertTrue($result->successful(), "worker {$i} process failed: {$result->errorOutput()}");
            $this->assertFileExists($out[$i], "worker {$i} did not write an output file");
            $results[] = json_decode(file_get_contents($out[$i]), true);
        }

        return $results;
    }
}

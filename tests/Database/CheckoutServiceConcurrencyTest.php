<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner requirement: prove the two-layer idempotency guarantee
 * (IdempotencyService + sales.UNIQUE(terminal_id, idempotency_key)) and
 * invoice-number allocation end to end THROUGH the real CheckoutService,
 * using genuinely separate OS processes/connections -- not merely at the
 * IdempotencyService/InvoiceSeriesAllocator layers directly, which
 * IdempotencyConcurrencyTest and InvoiceSeriesAllocatorConcurrencyTest
 * already cover. Same technique as both of those (real proc_open workers,
 * file-based barrier), applied one level up the stack.
 *
 * Does not extend PostgresSchemaTestCase: that base rolls back every
 * test's transaction in tearDown, which would make this test's fixtures
 * invisible to the separate worker processes (different connections,
 * different transactions -- MVCC visibility rules). Fixtures here are
 * committed normally and truncated explicitly between tests.
 */
class CheckoutServiceConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $terminalId;

    private string $cashierId;

    private string $productId;

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
        } else {
            foreach ([
                'electronic_journal_entries', 'audit_events', 'refund_settlements', 'refund_items', 'refunds', 'voids',
                'stock_movements', 'stock_balances', 'invoices',
                'payments', 'sale_items', 'sales', 'idempotency_records', 'invoice_series',
                'terminal_fiscal_installations', 'tax_registrations', 'inventory_locations',
                'fiscal_installations', 'products', 'shifts', 'fiscal_days', 'terminals', 'users', 'stores',
            ] as $table) {
                DB::table($table)->delete();
            }
        }

        $shift = Shift::factory()->create();
        $storeId = $shift->fiscalDay->store_id;
        $this->terminalId = $shift->terminal_id;
        $this->cashierId = $shift->cashier_id;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
        InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_id' => $this->terminalId,
            'fiscal_installation_id' => $fiscalInstallation->id, 'effective_from' => now()->subYear(),
            'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00'])->id;

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_checkout_race_'.Str::random(8);
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

    public function test_simultaneous_same_terminal_same_key_checkout_produces_exactly_one_sale(): void
    {
        $key = (string) Str::uuid();

        [$resultA, $resultB] = $this->race($key, $key);

        $this->assertSame('success', $resultA['outcome'], json_encode($resultA));
        $this->assertSame('success', $resultB['outcome'], json_encode($resultB));

        // The two-layer guarantee, proven through the real service: both
        // workers observed the SAME sale, and only one row exists.
        $this->assertSame($resultA['sale_id'], $resultB['sale_id']);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Invoice::count());

        $idempotencyRecord = DB::table('idempotency_records')
            ->where('terminal_id', $this->terminalId)->where('idempotency_key', $key)->sole();
        $this->assertSame('COMPLETED', $idempotencyRecord->status);
        $this->assertSame($resultA['sale_id'], $idempotencyRecord->result_resource_id);
    }

    public function test_concurrent_different_key_checkouts_produce_distinct_transaction_and_invoice_numbers(): void
    {
        $keyA = (string) Str::uuid();
        $keyB = (string) Str::uuid();

        [$resultA, $resultB] = $this->race($keyA, $keyB);

        $this->assertSame('success', $resultA['outcome'], json_encode($resultA));
        $this->assertSame('success', $resultB['outcome'], json_encode($resultB));

        // Stage 6C ruling on transaction_number: unique per store
        // (Stage 5 instruction SS64) -- proven here under genuine
        // concurrency, not merely by two sequential calls.
        $this->assertNotSame($resultA['transaction_number'], $resultB['transaction_number']);
        $this->assertNotSame($resultA['sale_id'], $resultB['sale_id']);

        // Invoice numbers: distinct, and together form exactly the
        // {starting_number, starting_number+1} pair with no gap -- the
        // same property InvoiceSeriesAllocatorConcurrencyTest proves at
        // the allocator layer directly, now proven through the full
        // CheckoutService orchestration.
        $numbers = collect([$resultA['invoice_number'], $resultB['invoice_number']])->map(fn ($n) => (int) $n)->sort()->values();
        $this->assertSame([1, 2], $numbers->all());

        $this->assertSame(2, Sale::count());
        $series = InvoiceSeries::first();
        $this->assertSame(2, $series->current_number);
    }

    /**
     * Gate 1's third required test (owner ruling on transaction_number):
     * a high-volume concurrency stress, matching the "10 workers"
     * pattern already established by InvoiceSeriesAllocatorConcurrencyTest.
     * Ten genuinely separate OS processes finalize ten distinct sales
     * for the same terminal/product at approximately the same instant;
     * this does not mathematically prove ULIDs can never collide (the
     * owner's own caveat) -- it proves the implementation behaves
     * correctly under real contention, with the database's
     * UNIQUE(store_id, transaction_number) constraint remaining the
     * final enforcement layer regardless.
     */
    public function test_high_volume_concurrent_checkouts_produce_unique_transaction_numbers(): void
    {
        $workerCount = 10;
        $keys = array_map(fn () => (string) Str::uuid(), range(1, $workerCount));

        $results = $this->raceN($workerCount, $keys);

        $succeeded = array_filter($results, fn ($r) => $r['outcome'] === 'success');
        $this->assertCount($workerCount, $succeeded, 'all workers must succeed -- got: '.json_encode($results));

        $transactionNumbers = array_map(fn ($r) => $r['transaction_number'], $succeeded);
        $this->assertCount($workerCount, array_unique($transactionNumbers), 'every concurrent checkout must receive a distinct transaction_number');

        $this->assertSame($workerCount, Sale::count());
        $this->assertSame($workerCount, DB::table('sales')->distinct()->count('transaction_number'));
    }

    /** @return array{0: array, 1: array} */
    private function race(string $keyA, string $keyB): array
    {
        $workerScript = base_path('tests/Database/support/checkout_worker.php');
        $readyA = $this->scratchDir.'/ready_a';
        $readyB = $this->scratchDir.'/ready_b';
        $go = $this->scratchDir.'/go';
        $outA = $this->scratchDir.'/out_a.json';
        $outB = $this->scratchDir.'/out_b.json';

        $processA = Process::start([PHP_BINARY, $workerScript, $this->terminalId, $this->cashierId, $keyA, $this->productId, $readyA, $go, $outA, self::DATABASE]);
        $processB = Process::start([PHP_BINARY, $workerScript, $this->terminalId, $this->cashierId, $keyB, $this->productId, $readyB, $go, $outB, self::DATABASE]);

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

    /** @return array<int, array<string, mixed>> */
    private function raceN(int $count, array $keys): array
    {
        $workerScript = base_path('tests/Database/support/checkout_worker.php');
        $ready = [];
        $out = [];
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $ready[$i] = $this->scratchDir."/ready_{$i}";
            $out[$i] = $this->scratchDir."/out_{$i}.json";
        }
        $go = $this->scratchDir.'/go';

        for ($i = 0; $i < $count; $i++) {
            $processes[$i] = Process::start([PHP_BINARY, $workerScript, $this->terminalId, $this->cashierId, $keys[$i], $this->productId, $ready[$i], $go, $out[$i], self::DATABASE]);
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

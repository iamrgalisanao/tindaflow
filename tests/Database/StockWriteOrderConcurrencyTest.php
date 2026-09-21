<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 28: the stock-write ordering invariant proved with real concurrent transactions, through the real
 * CheckoutService and StockTransferService in separate OS processes released together at a file barrier (like the other
 * concurrency tests it does not extend PostgresSchemaTestCase, so its fixtures are visible to the workers).
 *
 * Two terminals with DIFFERENT fiscal installations and invoice series are used on purpose. Checkouts that share an
 * invoice series are serialised by the series row lock, which checkout holds from allocation to commit, so they cannot
 * deadlock on stock rows; the cycle needs checkouts that do not share one, or a checkout against another stock writer.
 */
class StockWriteOrderConcurrencyTest extends TestCase
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

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_stock_order_'.Str::random(8);
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
     * One store with two terminals, each on its own fiscal installation and invoice series, two tracked products with
     * plenty of stock at the default location, and a second location.
     *
     * @return array{store: string, terminals: list<array{id: string, cashier: string}>, manager: User, products: array{0: Product, 1: Product}, shelf: InventoryLocation, backroom: InventoryLocation}
     */
    private function store(): array
    {
        $firstShift = Shift::factory()->create();
        $storeId = $firstShift->fiscalDay->store_id;
        $second = Terminal::factory()->create(['store_id' => $storeId]);
        $secondShift = Shift::factory()->create([
            'terminal_id' => $second->id,
            'fiscal_day_id' => FiscalDay::factory()->create(['store_id' => $storeId, 'terminal_id' => $second->id])->id,
        ]);

        $terminals = [];
        foreach ([$firstShift, $secondShift] as $position => $shift) {
            $installation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
            $code = 'SER'.($position + 1);
            InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $installation->id, 'series_code' => $code, 'prefix' => $code]);
            DB::table('terminal_fiscal_installations')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_id' => $shift->terminal_id,
                'fiscal_installation_id' => $installation->id, 'effective_from' => now()->subYear(),
                'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $terminals[] = ['id' => $shift->terminal_id, 'cashier' => $shift->cashier_id];
        }
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shelf = InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);
        $backroom = InventoryLocation::factory()->notDefault()->create(['store_id' => $storeId]);
        $manager = User::factory()->manager()->create(['store_id' => $storeId]);
        $products = [
            Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00']),
            Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00']),
        ];
        foreach ($products as $product) {
            DB::transaction(fn () => app(StockLedger::class)->record([
                'product_id' => $product->id, 'location_id' => $shelf->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => '1000', 'created_by' => $manager->id,
            ]));
        }

        return ['store' => $storeId, 'terminals' => $terminals, 'manager' => $manager, 'products' => $products, 'shelf' => $shelf, 'backroom' => $backroom];
    }

    /** @param  list<Product>  $order */
    private function checkoutJob(array $terminal, array $order): array
    {
        return [
            'type' => 'checkout', 'terminal_id' => $terminal['id'], 'cashier_id' => $terminal['cashier'], 'key' => (string) Str::uuid(),
            'items' => array_map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => '1'], $order),
            'amount' => number_format(100 * count($order), 2, '.', ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/stock_order_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            file_put_contents($this->scratchDir."/job_{$index}.json", json_encode($job));
            $processes[$index] = Process::start([
                PHP_BINARY, $script, $this->scratchDir."/job_{$index}.json", $this->scratchDir."/ready_{$index}",
                $go, $this->scratchDir."/out_{$index}.json", self::DATABASE,
            ]);
        }

        $deadline = microtime(true) + 30;
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

    private function onHand(Product $product, InventoryLocation $location): string
    {
        return (string) StockBalance::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity_on_hand');
    }

    public function test_checkouts_on_separate_series_taking_the_same_products_in_opposite_orders_never_deadlock(): void
    {
        $s = $this->store();
        [$first, $second] = $s['products'];
        $jobs = [];
        foreach (range(1, 4) as $unused) {
            $jobs[] = $this->checkoutJob($s['terminals'][0], [$first, $second]);
            $jobs[] = $this->checkoutJob($s['terminals'][1], [$second, $first]);
        }

        $outcomes = $this->race($jobs);

        $this->assertSame(array_fill(0, 8, 'success'), array_column($outcomes, 'outcome'), json_encode($outcomes));
        $this->assertSame(8, Sale::where('store_id', $s['store'])->count());
        foreach ($s['products'] as $product) {
            $this->assertSame('992.000', $this->onHand($product, $s['shelf']), 'every sale took exactly one of each');
        }
    }

    public function test_a_checkout_and_a_transfer_taking_the_same_rows_in_opposite_orders_never_deadlock(): void
    {
        $s = $this->store();
        // The canonical order is by product id. The checkouts scan in the REVERSE of it and the transfers list the
        // products in it, so an unordered checkout would take the two shelf rows the other way round to a transfer.
        $canonical = collect($s['products'])->sortBy('id')->values();
        [$early, $late] = [$canonical[0], $canonical[1]];
        $transfer = [
            'type' => 'transfer', 'terminal_id' => $s['terminals'][0]['id'], 'user_id' => $s['manager']->id,
            'payload' => ['from_location_id' => $s['shelf']->id, 'to_location_id' => $s['backroom']->id, 'items' => [
                ['product_id' => $early->id, 'quantity' => '1'], ['product_id' => $late->id, 'quantity' => '1'],
            ]],
        ];
        $jobs = [];
        foreach (range(1, 4) as $round) {
            $jobs[] = $this->checkoutJob($s['terminals'][$round % 2], [$late, $early]);
            $jobs[] = $transfer + ['key' => (string) Str::uuid()];
        }

        $outcomes = $this->race($jobs);

        $this->assertSame(array_fill(0, 8, 'success'), array_column($outcomes, 'outcome'), json_encode($outcomes));
        $this->assertSame(4, StockTransfer::where('store_id', $s['store'])->count());
        foreach ($s['products'] as $product) {
            $this->assertSame('992.000', $this->onHand($product, $s['shelf']), '1000 - 4 sold - 4 moved');
            $this->assertSame('4.000', $this->onHand($product, $s['backroom']));
        }
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();
    }
}

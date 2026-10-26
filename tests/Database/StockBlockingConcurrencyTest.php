<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Overrides\OverrideCodeService;
use App\Services\Overrides\StockOverrideService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-017 slice A, invariants.md STK-001 and STK-005: the stock check holds when tills race. Real OS processes with their own connections,
 * released together, sell through the real CheckoutService; the decisive check runs on stock rows locked FOR UPDATE, so the second till sees
 * the first one's deduction. Slip mode keeps the fixtures small. Like the other concurrency tests this does not roll back per test: the
 * workers are other connections.
 */
class StockBlockingConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private string $scratchDir;

    private string $storeId;

    private string $locationId;

    private string $adminId;

    /** @var list<array{terminal: string, cashier: string}> */
    private array $tills = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);

        $first = Shift::factory()->create();
        $this->storeId = $first->fiscalDay->store_id;
        $this->tills = [['terminal' => $first->terminal_id, 'cashier' => $first->cashier_id]];
        foreach ([1, 2, 3] as $ignored) {
            $terminal = Terminal::factory()->create(['store_id' => $this->storeId]);
            $cashier = User::factory()->create(['store_id' => $this->storeId]);
            Shift::factory()->create(['terminal_id' => $terminal->id, 'fiscal_day_id' => FiscalDay::factory()->create(['store_id' => $this->storeId, 'terminal_id' => $terminal->id])->id, 'cashier_id' => $cashier->id]);
            $this->tills[] = ['terminal' => $terminal->id, 'cashier' => $cashier->id];
        }

        $this->locationId = InventoryLocation::factory()->create(['store_id' => $this->storeId, 'is_default' => true])->id;
        $this->adminId = User::factory()->admin()->create(['store_id' => $this->storeId])->id;
        DB::table('store_settings')->insert([
            'store_id' => $this->storeId, 'business_name' => 'Canteen', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP',
            'block_sale_without_stock' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_stock_race_'.Str::random(8);
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

    private function product(string $price = '10.00'): Product
    {
        return Product::factory()->create(['store_id' => $this->storeId, 'selling_price' => $price, 'reorder_level' => null]);
    }

    private function stock(Product $product, string $quantity): void
    {
        DB::transaction(fn () => app(StockLedger::class)->record([
            'product_id' => $product->id, 'location_id' => $this->locationId, 'movement_type' => 'OPENING_STOCK', 'quantity' => $quantity,
            'reference_type' => 'test', 'reference_id' => (string) Str::uuid(), 'created_by' => $this->adminId,
        ]));
    }

    private function onHand(Product $product): ?string
    {
        $value = DB::table('stock_balances')->where('product_id', $product->id)->value('quantity_on_hand');

        return $value === null ? null : bcadd((string) $value, '0', 3);
    }

    /**
     * @param  list<array{0: Product, 1: string}>  $lines
     * @return array<string, mixed>
     */
    private function job(int $till, array $lines, array $extra = []): array
    {
        return [
            'terminal_id' => $this->tills[$till]['terminal'], 'cashier_id' => $this->tills[$till]['cashier'], 'key' => (string) Str::uuid(),
            'lines' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'total' => array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, bcmul((string) $line[0]->selling_price, $line[1], 2), 2), '0.00'),
            'pause_ms' => 300,
        ] + $extra;
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/stock_block_worker.php');
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

    public function test_two_tills_selling_the_last_unit_exactly_one_succeeds_and_the_balance_is_zero(): void
    {
        $coke = $this->product();
        $this->stock($coke, '1');

        $outcomes = $this->race([$this->job(0, [[$coke, '1']]), $this->job(1, [[$coke, '1']])]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $refused = array_values(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exception'));
        $this->assertCount(1, $refused, json_encode($outcomes));
        $this->assertSame('INSUFFICIENT_STOCK', $refused[0]['code'], json_encode($refused));
        $this->assertSame('0.000', $refused[0]['details']['shortfalls'][0]['available'], 'the loser saw the winner\'s deduction');

        $this->assertSame('0.000', $this->onHand($coke));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame(1, DB::table('sale_slips')->count());
        $this->assertSame(1, DB::table('idempotency_records')->where('status', 'COMPLETED')->count());
        $this->assertSame(1, DB::table('idempotency_records')->count(), 'the refused attempt left no reservation behind');
    }

    public function test_four_tills_for_three_units_sell_exactly_three(): void
    {
        $coke = $this->product();
        $this->stock($coke, '3');

        $outcomes = $this->race(array_map(fn (int $till) => $this->job($till, [[$coke, '1']]), [0, 1, 2, 3]));

        $this->assertCount(3, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        foreach (array_filter($outcomes, fn ($o) => $o['outcome'] !== 'success') as $refused) {
            $this->assertSame('INSUFFICIENT_STOCK', $refused['code'], json_encode($refused));
        }
        $this->assertSame('0.000', $this->onHand($coke));
        $this->assertSame(3, DB::table('sales')->count());
    }

    public function test_a_product_that_has_never_had_a_stock_record_refuses_every_racing_till_and_leaves_no_row(): void
    {
        $coke = $this->product();

        $outcomes = $this->race([$this->job(0, [[$coke, '1']]), $this->job(1, [[$coke, '1']])]);

        foreach ($outcomes as $outcome) {
            $this->assertSame('exception', $outcome['outcome'], json_encode($outcomes));
            $this->assertSame('INSUFFICIENT_STOCK', $outcome['code'], json_encode($outcome));
        }
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('stock_balances')->count(), 'a refused sale\'s zero row rolled back');
    }

    public function test_tills_scanning_the_same_products_in_opposite_orders_do_not_deadlock(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->stock($a, '10');
        $this->stock($b, '10');

        $outcomes = $this->race([
            $this->job(0, [[$a, '1'], [$b, '1']]), $this->job(1, [[$b, '1'], [$a, '1']]),
            $this->job(2, [[$a, '1'], [$b, '1']]), $this->job(3, [[$b, '1'], [$a, '1']]),
        ]);

        foreach ($outcomes as $outcome) {
            $this->assertSame('success', $outcome['outcome'], json_encode($outcome));
        }
        $this->assertSame('6.000', $this->onHand($a));
        $this->assertSame('6.000', $this->onHand($b));
    }

    public function test_one_manager_approval_used_by_two_racing_short_sales_is_consumed_by_exactly_one(): void
    {
        $coke = $this->product();
        $manager = User::factory()->manager()->create(['store_id' => $this->storeId]);
        app(OverrideCodeService::class)->setOwn($manager, 'password', '482916');
        $cashier = User::findOrFail($this->tills[0]['cashier']);
        $token = app(StockOverrideService::class)->grantForCart($cashier, Terminal::findOrFail($this->tills[0]['terminal']), $manager->id, '482916', [['product_id' => $coke->id, 'quantity' => '1']])['token'];

        // Nothing in stock; the same till and token, two different keys (so neither is an idempotent replay of the other).
        $outcomes = $this->race([
            $this->job(0, [[$coke, '1']], ['stock_override_token' => $token]),
            $this->job(0, [[$coke, '1']], ['stock_override_token' => $token]),
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $refused = array_values(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exception'));
        $this->assertCount(1, $refused, json_encode($outcomes));
        $this->assertSame('STOCK_OVERRIDE_EXPIRED', $refused[0]['code'], json_encode($refused));

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('-1.000', $this->onHand($coke));
        $this->assertSame(1, DB::table('stock_override_grants')->whereNotNull('consumed_at')->whereNotNull('sale_id')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'STOCK_OVERRIDE_USED')->count());
        $this->assertSame(['ALREADY_USED'], DB::table('audit_events')->where('event_type', 'STOCK_OVERRIDE_REFUSED')->pluck('reason')->all(), 'the loser is audited although its sale rolled back');
    }

    public function test_a_grant_raced_by_a_sale_that_needs_it_and_one_that_does_not_is_consumed_only_by_the_one_that_needs_it(): void
    {
        $short = $this->product();
        $fine = $this->product();
        $this->stock($fine, '5');
        $manager = User::factory()->manager()->create(['store_id' => $this->storeId]);
        app(OverrideCodeService::class)->setOwn($manager, 'password', '482916');
        $cashier = User::findOrFail($this->tills[0]['cashier']);
        $token = app(StockOverrideService::class)->grantForCart($cashier, Terminal::findOrFail($this->tills[0]['terminal']), $manager->id, '482916', [['product_id' => $short->id, 'quantity' => '1']])['token'];

        $outcomes = $this->race([
            $this->job(0, [[$fine, '1']], ['stock_override_token' => $token]),
            $this->job(0, [[$short, '1']], ['stock_override_token' => $token]),
        ]);

        // The sale of the in-stock item never needs the grant. The short one is bound to its own cart, so it succeeds whichever ran first.
        foreach ($outcomes as $outcome) {
            $this->assertSame('success', $outcome['outcome'], json_encode($outcome));
        }
        $grant = DB::table('stock_override_grants')->first();
        $sale = DB::table('sale_items')->where('product_id', $short->id)->value('sale_id');
        $this->assertSame($sale, $grant->sale_id, 'only the short sale consumed it');
    }
}

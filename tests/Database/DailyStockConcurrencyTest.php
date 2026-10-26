<?php

namespace Tests\Database;

use App\Models\Dish;
use App\Models\FiscalDay;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsDailyStockScenario;
use Tests\TestCase;

/**
 * ADR-017 slice B, invariants.md STK-002 and STK-003: daily stock holds when tills race. Real OS processes with their own connections, released together, sell
 * through the real CheckoutService and save through the real DailyStockService; the decisive check runs on daily rows locked FOR UPDATE in the canonical order
 * (product, then stock date), so two tills selling the last portion (as a meal or as an ala carte order) cannot both succeed, a checkout against a daily
 * entry leaves a ledger that equals its cache, and tills taking two dishes in opposite orders do not deadlock. Slip mode keeps the fixtures small.
 */
class DailyStockConcurrencyTest extends TestCase
{
    use BuildsDailyStockScenario;

    private const DATABASE = 'tindaflow_concurrency_test';

    private string $scratchDir;

    private string $storeId;

    private string $locationId;

    /** @var array<string, mixed> */
    private array $menu;

    /** @var list<array{terminal: string, cashier: string}> */
    private array $tills = [];

    private User $manager;

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
        $admin = User::factory()->admin()->create(['store_id' => $this->storeId]);
        $this->manager = User::factory()->manager()->create(['store_id' => $this->storeId]);
        DB::table('store_settings')->insert([
            'store_id' => $this->storeId, 'business_name' => 'Canteen', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP',
            'block_sale_without_stock' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->menu = $this->dailyMenu(['admin' => $admin, 'storeId' => $this->storeId]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_daily_race_'.Str::random(8);
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

    private function stockDate(): string
    {
        return StockRules::forStore($this->storeId)->stockDateAt(now());
    }

    /** Puts cooked portions on the books through the ledger, as the morning entry would. */
    private function cook(Dish $dish, string $portions): void
    {
        DB::transaction(fn () => app(StockLedger::class)->record([
            'product_id' => $dish->fresh()->stock_product_id, 'location_id' => $this->locationId, 'movement_type' => 'DAILY_PORTIONS_ADDED', 'quantity' => $portions,
            'stock_date' => $this->stockDate(), 'created_by' => $this->manager->id,
        ]));
    }

    private function left(Dish $dish): string
    {
        return bcadd((string) DB::table('daily_stock_balances')->where('product_id', $dish->fresh()->stock_product_id)->where('stock_date', $this->stockDate())->value('quantity_on_hand'), '0', 3);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sale(int $till, array $items, array $extra = []): array
    {
        return ['kind' => 'sale', 'terminal_id' => $this->tills[$till]['terminal'], 'cashier_id' => $this->tills[$till]['cashier'], 'key' => (string) Str::uuid(), 'items' => $items, 'total' => '100000.00', 'pause_ms' => 300] + $extra;
    }

    /** @return array<string, mixed> */
    private function entry(array $lines, ?string $reason = null, int $pause = 300): array
    {
        return ['kind' => 'entry', 'terminal_id' => $this->tills[0]['terminal'], 'user_id' => $this->manager->id, 'key' => (string) Str::uuid(), 'lines' => $lines, 'reason' => $reason, 'pause_ms' => $pause];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            $script = base_path($job['kind'] === 'entry' ? 'tests/Database/support/daily_entry_worker.php' : 'tests/Database/support/stock_block_worker.php');
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

    private function assertLedgerEqualsCache(): void
    {
        $outflow = "'".implode("','", StockLedger::OUTFLOW)."'";
        $ledger = DB::table('stock_movements')->whereNotNull('stock_date')->selectRaw("product_id, stock_date, SUM(CASE WHEN movement_type IN ({$outflow}) THEN -quantity ELSE quantity END) AS net")->groupBy('product_id', 'stock_date')->get();
        foreach ($ledger as $row) {
            $this->assertSame(
                bcadd((string) $row->net, '0', 3),
                bcadd((string) DB::table('daily_stock_balances')->where('product_id', $row->product_id)->where('stock_date', $row->stock_date)->value('quantity_on_hand'), '0', 3),
                'the daily cache equals the ledger',
            );
        }
    }

    public function test_two_tills_selling_the_last_portion_as_a_meal_exactly_one_succeeds(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '1');

        $outcomes = $this->race([$this->sale(0, [$this->standardItem($m, $m['nilagaOption'])]), $this->sale(1, [$this->standardItem($m, $m['nilagaOption'])])]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $refused = array_values(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exception'));
        $this->assertSame('INSUFFICIENT_STOCK', $refused[0]['code'], json_encode($refused));
        $this->assertSame('0.000', $refused[0]['details']['shortfalls'][0]['available'], 'the loser saw the winner\'s deduction');
        $this->assertSame('DISH', $refused[0]['details']['shortfalls'][0]['kind']);
        $this->assertSame('0.000', $this->left($m['nilaga']));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('idempotency_records')->count(), 'the refused attempt left no reservation behind');
        $this->assertLedgerEqualsCache();
    }

    public function test_a_meal_and_an_ala_carte_order_racing_for_the_same_dish_cannot_both_have_it(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '2');

        // The plate (1) and the ala carte order (2) together need 3 of 2: exactly one of them gets it.
        $outcomes = $this->race([$this->sale(0, [$this->standardItem($m, $m['nilagaOption'])]), $this->sale(1, [$this->alaItem($m['alaNilaga'])])]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $left = $this->left($m['nilaga']);
        $this->assertContains($left, ['1.000', '0.000'], 'the plate leaves 1, the ala carte order leaves 0');
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertLedgerEqualsCache();
    }

    public function test_four_tills_for_five_portions_of_ala_carte_sell_exactly_two_orders(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '5');

        $outcomes = $this->race(array_map(fn (int $till) => $this->sale($till, [$this->alaItem($m['alaNilaga'])]), [0, 1, 2, 3]));

        $this->assertCount(2, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        foreach (array_filter($outcomes, fn ($o) => $o['outcome'] !== 'success') as $refused) {
            $this->assertSame('INSUFFICIENT_STOCK', $refused['code'], json_encode($refused));
        }
        $this->assertSame('1.000', $this->left($m['nilaga']));
        $this->assertLedgerEqualsCache();
    }

    public function test_tills_taking_two_dishes_in_opposite_orders_do_not_deadlock_and_the_pools_stay_exact(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '20');
        $this->cook($m['pinakbet'], '20');
        $a = [$this->alaItem($m['alaNilaga']), $this->alaItem($m['alaPinakbet'])];

        $outcomes = $this->race([
            $this->sale(0, $a), $this->sale(1, array_reverse($a)), $this->sale(2, $a), $this->sale(3, array_reverse($a)),
        ]);

        foreach ($outcomes as $outcome) {
            $this->assertSame('success', $outcome['outcome'], json_encode($outcome));
        }
        $this->assertSame('12.000', $this->left($m['nilaga']));
        $this->assertSame('12.000', $this->left($m['pinakbet']));
        $this->assertLedgerEqualsCache();
    }

    public function test_a_checkout_racing_a_daily_entry_that_lowers_the_total_one_of_them_wins_and_the_ledger_stays_exact(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '3');

        // The sale takes 2 of 3. The entry lowers the total to 1: valid only if it runs before the sale (then the sale needs 2 of 1 and is refused);
        // after the sale (2 sold) it would take the remaining below zero and is refused as DAILY_STOCK_BELOW_SOLD.
        $outcomes = $this->race([
            $this->sale(0, [$this->alaItem($m['alaNilaga'])]),
            $this->entry([['dish_id' => $m['nilaga']->id, 'cooked' => '1']], 'Miscount'),
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $loser = array_values(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exception'))[0];
        $this->assertContains($loser['code'], ['INSUFFICIENT_STOCK', 'DAILY_STOCK_BELOW_SOLD'], json_encode($loser));
        $this->assertGreaterThanOrEqual(0, (float) $this->left($m['nilaga']), 'neither path leaves the day negative');
        $this->assertLedgerEqualsCache();
    }

    public function test_a_checkout_racing_a_daily_entry_that_adds_both_succeed_and_nothing_is_lost(): void
    {
        $m = $this->menu;
        $this->cook($m['nilaga'], '2');

        $outcomes = $this->race([
            $this->sale(0, [$this->alaItem($m['alaNilaga'])]),
            $this->entry([['dish_id' => $m['nilaga']->id, 'add' => '5']]),
        ]);

        foreach ($outcomes as $outcome) {
            $this->assertSame('success', $outcome['outcome'], json_encode($outcomes));
        }
        $this->assertSame('5.000', $this->left($m['nilaga']), '2 + 5 cooked, 2 sold');
        $this->assertLedgerEqualsCache();
    }

    public function test_two_managers_saving_the_grid_at_once_both_apply_as_adds_and_the_total_is_the_sum(): void
    {
        $m = $this->menu;

        $outcomes = $this->race([
            $this->entry([['dish_id' => $m['nilaga']->id, 'add' => '4'], ['dish_id' => $m['pinakbet']->id, 'add' => '1']]),
            $this->entry([['dish_id' => $m['pinakbet']->id, 'add' => '2'], ['dish_id' => $m['nilaga']->id, 'add' => '3']]),
        ]);

        foreach ($outcomes as $outcome) {
            $this->assertSame('success', $outcome['outcome'], json_encode($outcomes));
        }
        $this->assertSame('7.000', $this->left($m['nilaga']));
        $this->assertSame('3.000', $this->left($m['pinakbet']));
        $this->assertLedgerEqualsCache();
    }
}

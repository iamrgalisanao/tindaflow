<?php

namespace Tests\Database;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
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
 * Stage 25: what the sequential HTTP tests cannot show, using independent OS processes with their own PostgreSQL
 * connections released together at a file barrier (the same approach as the other concurrency tests; like them it
 * does not extend PostgresSchemaTestCase, so its fixtures are committed and visible to the workers).
 *
 *  - two people posting the same count at once adjust stock exactly once (the row lock on the count),
 *  - a post racing a cancel has one winner and leaves the ledger consistent with it,
 *  - transfers moving the same products in opposite directions never deadlock (fixed ledger write order).
 */
class StockCountTransferConcurrencyTest extends TestCase
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

    /** @return array{terminal: Terminal, user: User, location: InventoryLocation, other: InventoryLocation} */
    private function store(): array
    {
        $terminal = Terminal::factory()->create();
        $storeId = $terminal->store_id;

        return [
            'terminal' => $terminal,
            'user' => User::factory()->manager()->create(['store_id' => $storeId]),
            'location' => InventoryLocation::factory()->create(['store_id' => $storeId, 'name' => 'Counter']),
            'other' => InventoryLocation::factory()->notDefault()->create(['store_id' => $storeId, 'name' => 'Backroom']),
        ];
    }

    private function onHand(Product $product, InventoryLocation $location, string $quantity, User $by): void
    {
        DB::transaction(fn () => app(StockLedger::class)->record([
            'product_id' => $product->id, 'location_id' => $location->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => $quantity, 'created_by' => $by->id,
        ]));
    }

    private function balance(Product $product, InventoryLocation $location): string
    {
        return (string) StockBalance::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity_on_hand');
    }

    /**
     * Starts one worker per job, waits until all are ready, releases them together and returns their outcomes.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/stock_race_worker.php');
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

    /** @return array<string, mixed> */
    private function job(string $type, array $s, array $extra): array
    {
        return ['type' => $type, 'terminal_id' => $s['terminal']->id, 'user_id' => $s['user']->id, 'key' => (string) Str::uuid()] + $extra;
    }

    private function countedShort(array $s, Product $product): StockCount
    {
        $count = StockCount::create(['store_id' => $s['terminal']->store_id, 'location_id' => $s['location']->id, 'status' => 'OPEN', 'created_by' => $s['user']->id]);
        StockCountLine::create([
            'stock_count_id' => $count->id, 'product_id' => $product->id, 'counted_quantity' => '8.000', 'expected_quantity' => '10.000',
            'counted_by' => $s['user']->id, 'counted_at' => now(),
        ]);

        return $count;
    }

    public function test_two_people_posting_the_same_count_adjust_stock_once(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['terminal']->store_id]);
        $this->onHand($product, $s['location'], '10', $s['user']);
        $count = $this->countedShort($s, $product);

        $outcomes = $this->race([
            $this->job('post_count', $s, ['stock_count_id' => $count->id]),
            $this->job('post_count', $s, ['stock_count_id' => $count->id]),
        ]);

        $this->assertEqualsCanonicalizing(['success', 'exception'], array_column($outcomes, 'outcome'), json_encode($outcomes));
        $loser = collect($outcomes)->firstWhere('outcome', 'exception');
        $this->assertSame('StockCountNotOpenException', $loser['class']);

        $this->assertSame('POSTED', $count->refresh()->status);
        $this->assertSame(1, StockMovement::where('reference_type', 'stock_count')->where('reference_id', $count->id)->count(), 'the correction is written once');
        $this->assertSame('8.000', $this->balance($product, $s['location']));
    }

    public function test_a_post_racing_a_cancel_has_one_winner_and_the_ledger_agrees_with_it(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['terminal']->store_id]);
        $this->onHand($product, $s['location'], '10', $s['user']);
        $count = $this->countedShort($s, $product);

        $outcomes = $this->race([
            $this->job('post_count', $s, ['stock_count_id' => $count->id]),
            $this->job('cancel_count', $s, ['stock_count_id' => $count->id]),
        ]);

        $this->assertEqualsCanonicalizing(['success', 'exception'], array_column($outcomes, 'outcome'), json_encode($outcomes));
        $count->refresh();
        $adjustments = StockMovement::where('reference_type', 'stock_count')->where('reference_id', $count->id)->count();

        if ($count->status === 'POSTED') {
            $this->assertSame(1, $adjustments);
            $this->assertSame('8.000', $this->balance($product, $s['location']));
        } else {
            $this->assertSame('CANCELLED', $count->status);
            $this->assertSame(0, $adjustments, 'a cancelled count writes nothing');
            $this->assertSame('10.000', $this->balance($product, $s['location']));
        }
    }

    public function test_transfers_in_opposite_directions_over_the_same_products_never_deadlock(): void
    {
        $s = $this->store();
        $storeId = $s['terminal']->store_id;
        $first = Product::factory()->create(['store_id' => $storeId]);
        $second = Product::factory()->create(['store_id' => $storeId]);
        foreach ([$first, $second] as $product) {
            $this->onHand($product, $s['location'], '100', $s['user']);
            $this->onHand($product, $s['other'], '100', $s['user']);
        }

        // Each direction lists the products in the opposite order to the other: without a fixed write order,
        // two of these would each hold one balance row and wait for the other's.
        $forward = ['from_location_id' => $s['location']->id, 'to_location_id' => $s['other']->id, 'items' => [
            ['product_id' => $first->id, 'quantity' => '1'], ['product_id' => $second->id, 'quantity' => '1'],
        ]];
        $backward = ['from_location_id' => $s['other']->id, 'to_location_id' => $s['location']->id, 'items' => [
            ['product_id' => $second->id, 'quantity' => '2'], ['product_id' => $first->id, 'quantity' => '2'],
        ]];
        $jobs = [];
        foreach (range(1, 4) as $unused) {
            $jobs[] = $this->job('transfer', $s, ['payload' => $forward]);
            $jobs[] = $this->job('transfer', $s, ['payload' => $backward]);
        }

        $outcomes = $this->race($jobs);

        $this->assertSame(array_fill(0, 8, 'success'), array_column($outcomes, 'outcome'), json_encode($outcomes));
        $this->assertSame(8, StockTransfer::where('store_id', $storeId)->count());
        foreach ([$first, $second] as $product) {
            $this->assertSame('104.000', $this->balance($product, $s['location']), '100 - 4*1 + 4*2');
            $this->assertSame('96.000', $this->balance($product, $s['other']), '100 + 4*1 - 4*2');
        }
    }
}

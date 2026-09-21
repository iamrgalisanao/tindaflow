<?php

namespace Tests\Database;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Stage 28: the concurrency invariant for stock. Every transaction that writes more than one stock balance row writes
 * them in the canonical order (location, then product), whatever order the caller listed them in, because two
 * transactions that take the same two rows in opposite orders deadlock. This proves the ordering at the ledger itself;
 * StockWriteOrderConcurrencyTest proves it with real concurrent transactions.
 */
class StockLedgerOrderingTest extends PostgresSchemaTestCase
{
    private function ledger(): StockLedger
    {
        return new StockLedger;
    }

    /** @return array{store: Store, user: User, locations: array<int, InventoryLocation>, products: array<int, Product>} */
    private function world(): array
    {
        $store = Store::factory()->create();

        return [
            'store' => $store,
            'user' => User::factory()->create(['store_id' => $store->id]),
            'locations' => [
                InventoryLocation::factory()->create(['store_id' => $store->id]),
                InventoryLocation::factory()->notDefault()->create(['store_id' => $store->id]),
            ],
            'products' => Product::factory()->count(3)->create(['store_id' => $store->id])->all(),
        ];
    }

    private function movement(array $w, Product $product, InventoryLocation $location, string $quantity = '1', string $type = 'SALE'): array
    {
        return [
            'product_id' => $product->id, 'location_id' => $location->id, 'movement_type' => $type,
            'quantity' => $quantity, 'created_by' => $w['user']->id,
        ];
    }

    /**
     * The (location, product) pairs the balance upserts were issued for, in the order they were issued.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function recordedOrder(callable $run): array
    {
        $issued = [];
        DB::listen(function ($query) use (&$issued) {
            if (str_starts_with(trim($query->sql), 'INSERT INTO stock_balances')) {
                $issued[] = [$query->bindings[1], $query->bindings[0]]; // [location_id, product_id]
            }
        });

        $run();

        return $issued;
    }

    public function test_movements_are_written_in_canonical_order_whatever_order_they_are_given(): void
    {
        $w = $this->world();
        [$first, $second, $third] = $w['products'];
        [$shelf] = $w['locations'];
        $given = [$this->movement($w, $third, $shelf), $this->movement($w, $first, $shelf), $this->movement($w, $second, $shelf)];
        $expected = collect([$first, $second, $third])->pluck('id')->sort()->values()->all();

        $order = $this->recordedOrder(fn () => DB::transaction(fn () => $this->ledger()->recordMany($given)));

        $this->assertSame($expected, array_column($order, 1), 'by product id, not by the order the cashier scanned');
    }

    public function test_every_permutation_of_the_same_movements_is_written_in_the_same_order(): void
    {
        $w = $this->world();
        [$a, $b, $c] = $w['products'];
        [$shelf, $backroom] = $w['locations'];
        $movements = [
            $this->movement($w, $a, $shelf), $this->movement($w, $b, $shelf), $this->movement($w, $c, $backroom), $this->movement($w, $a, $backroom),
        ];
        $seen = [];

        foreach ([[0, 1, 2, 3], [3, 2, 1, 0], [2, 0, 3, 1], [1, 3, 0, 2]] as $permutation) {
            $given = array_map(fn (int $i) => $movements[$i], $permutation);
            $order = $this->recordedOrder(fn () => DB::transaction(fn () => $this->ledger()->recordMany($given)));
            $seen[] = json_encode($order);
        }

        $this->assertCount(1, array_unique($seen), 'the write order must not depend on the order given');
        $sorted = json_decode($seen[0], true);
        $this->assertSame($sorted, collect($sorted)->sort(fn ($x, $y) => strcmp($x[0], $y[0]) ?: strcmp($x[1], $y[1]))->values()->all(), 'sorted by location, then product');
    }

    public function test_the_movements_come_back_in_the_order_they_were_given(): void
    {
        $w = $this->world();
        [$a, $b, $c] = $w['products'];
        [$shelf] = $w['locations'];
        $given = [$this->movement($w, $c, $shelf, '3'), $this->movement($w, $a, $shelf, '1'), $this->movement($w, $b, $shelf, '2')];

        $recorded = DB::transaction(fn () => $this->ledger()->recordMany($given));

        $this->assertSame([$c->id, $a->id, $b->id], array_column(array_map(fn ($m) => $m->only('product_id'), $recorded), 'product_id'));
        $this->assertSame(['3.000', '1.000', '2.000'], array_map(fn ($m) => $m->quantity, $recorded), 'each result is the movement for the line at the same position');
    }

    public function test_two_movements_of_the_same_row_stay_in_the_order_given_and_are_not_merged(): void
    {
        $w = $this->world();
        [$a] = $w['products'];
        [$shelf] = $w['locations'];
        $given = [$this->movement($w, $a, $shelf, '2'), $this->movement($w, $a, $shelf, '5')];

        $recorded = DB::transaction(fn () => $this->ledger()->recordMany($given));

        $this->assertSame(['2.000', '5.000'], array_map(fn ($m) => $m->quantity, $recorded));
        $this->assertNotSame($recorded[0]->id, $recorded[1]->id, 'one movement per line: a void or refund restores exactly what each line took');
        $this->assertSame('-7.000', StockBalance::where('product_id', $a->id)->value('quantity_on_hand'));
    }

    public function test_the_balances_are_right_and_an_empty_call_is_harmless(): void
    {
        $w = $this->world();
        [$a, $b] = $w['products'];
        [$shelf] = $w['locations'];

        DB::transaction(function () use ($w, $a, $b, $shelf) {
            $this->assertSame([], $this->ledger()->recordMany([]));
            $this->ledger()->recordMany([
                $this->movement($w, $b, $shelf, '4', 'PURCHASE_RECEIPT'), $this->movement($w, $a, $shelf, '10', 'PURCHASE_RECEIPT'), $this->movement($w, $b, $shelf, '1'),
            ]);
        });

        $this->assertSame('10.000', StockBalance::where('product_id', $a->id)->value('quantity_on_hand'));
        $this->assertSame('3.000', StockBalance::where('product_id', $b->id)->value('quantity_on_hand'));
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();
    }
}

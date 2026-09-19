<?php

namespace Tests\Database;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Invariants #44/#45/#47: the ledger is the only writer of stock_movements and stock_balances, and the
 * balance is always the signed sum of the movements.
 */
class StockLedgerTest extends PostgresSchemaTestCase
{
    private function record(StockLedger $ledger, Product $product, InventoryLocation $location, User $user, string $type, string $quantity): StockMovement
    {
        return DB::transaction(fn () => $ledger->record([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reason' => in_array($type, ['STOCK_ADJUSTMENT_IN', 'STOCK_ADJUSTMENT_OUT', 'DAMAGE', 'EXPIRED'], true) ? 'test' : null,
            'created_by' => $user->id,
        ]));
    }

    private function balance(Product $product, InventoryLocation $location): ?string
    {
        return StockBalance::where('product_id', $product->id)->where('location_id', $location->id)->first()?->quantity_on_hand;
    }

    /** @return array{Product, InventoryLocation, User} */
    private function fixtures(): array
    {
        $user = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $user->store_id]);
        $location = InventoryLocation::factory()->create(['store_id' => $user->store_id]);

        return [$product, $location, $user];
    }

    public function test_inflows_create_and_increase_the_balance_and_outflows_decrease_it(): void
    {
        [$product, $location, $user] = $this->fixtures();
        $ledger = new StockLedger;

        $this->assertNull($this->balance($product, $location));

        $this->record($ledger, $product, $location, $user, 'PURCHASE_RECEIPT', '10');
        $this->assertSame('10.000', $this->balance($product, $location));

        $this->record($ledger, $product, $location, $user, 'STOCK_ADJUSTMENT_IN', '2.5');
        $this->record($ledger, $product, $location, $user, 'SALE', '3');
        $this->record($ledger, $product, $location, $user, 'DAMAGE', '0.5');
        $this->record($ledger, $product, $location, $user, 'EXPIRED', '1');
        $this->record($ledger, $product, $location, $user, 'SALE_RETURN', '1');

        // 10 + 2.5 - 3 - 0.5 - 1 + 1
        $this->assertSame('9.000', $this->balance($product, $location));
    }

    public function test_movements_are_stored_positive_and_the_balance_can_go_below_zero(): void
    {
        [$product, $location, $user] = $this->fixtures();
        $ledger = new StockLedger;

        $movement = $this->record($ledger, $product, $location, $user, 'SALE', '4');

        $this->assertSame('4.000', $movement->quantity);
        $this->assertSame('-4.000', $this->balance($product, $location));
    }

    public function test_the_balance_always_equals_the_signed_sum_of_the_movements(): void
    {
        [$product, $location, $user] = $this->fixtures();
        $ledger = new StockLedger;

        foreach ([['OPENING_STOCK', '20'], ['SALE', '7.25'], ['TRANSFER_OUT', '1'], ['TRANSFER_IN', '4.125'], ['STOCK_ADJUSTMENT_OUT', '0.375']] as [$type, $quantity]) {
            $this->record($ledger, $product, $location, $user, $type, $quantity);
        }

        $signedSum = StockMovement::where('product_id', $product->id)->get()
            ->reduce(fn ($sum, $movement) => bcadd($sum, bcmul($movement->quantity, (string) StockLedger::direction($movement->movement_type), 3), 3), '0.000');

        $this->assertSame($signedSum, $this->balance($product, $location));
        $this->assertSame('15.500', $signedSum);
    }

    public function test_balances_are_kept_per_product_and_location(): void
    {
        [$product, $location, $user] = $this->fixtures();
        $otherLocation = InventoryLocation::factory()->notDefault()->create(['store_id' => $user->store_id]);
        $ledger = new StockLedger;

        $this->record($ledger, $product, $location, $user, 'PURCHASE_RECEIPT', '5');
        $this->record($ledger, $product, $otherLocation, $user, 'PURCHASE_RECEIPT', '8');

        $this->assertSame('5.000', $this->balance($product, $location));
        $this->assertSame('8.000', $this->balance($product, $otherLocation));
    }

    public function test_an_unknown_movement_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockLedger::direction('MYSTERY');
    }

    public function test_the_rebuild_command_repairs_drifted_and_missing_balances_and_supports_a_dry_run(): void
    {
        [$product, $location, $user] = $this->fixtures();
        $ledger = new StockLedger;
        $this->record($ledger, $product, $location, $user, 'PURCHASE_RECEIPT', '10');
        $this->record($ledger, $product, $location, $user, 'SALE', '4');

        $orphan = Product::factory()->create(['store_id' => $user->store_id]);
        StockBalance::create(['product_id' => $orphan->id, 'location_id' => $location->id, 'quantity_on_hand' => '9.000']);
        DB::table('stock_balances')->where('product_id', $product->id)->update(['quantity_on_hand' => '99.000']);

        $this->artisan('inventory:rebuild-balances --dry-run')->assertSuccessful();
        $this->assertSame('99.000', $this->balance($product, $location));

        $this->artisan('inventory:rebuild-balances')->assertSuccessful();
        $this->assertSame('6.000', $this->balance($product, $location));
        $this->assertSame('0.000', $this->balance($orphan, $location));

        $this->artisan('inventory:rebuild-balances')->expectsOutputToContain('already equals')->assertSuccessful();
    }
}

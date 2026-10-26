<?php

namespace Tests\Unit\Services\Inventory;

use App\Models\Product;
use App\Services\Inventory\StockDemandResolver;
use App\Services\Inventory\StockRules;
use PHPUnit\Framework\TestCase;

/** ADR-017 section 5/6, invariants.md STK-001: the pure part of the stock check. */
class StockDemandResolverTest extends TestCase
{
    private function product(string $id, string $name, bool $tracked = true): Product
    {
        $product = new Product(['name' => $name, 'track_inventory' => $tracked]);
        $product->id = $id;

        return $product;
    }

    public function test_the_demands_of_one_product_are_added_up_and_untracked_products_demand_nothing(): void
    {
        $coke = $this->product('a', 'Coke');
        $service = $this->product('b', 'Haircut', tracked: false);

        $demands = (new StockDemandResolver)->fromLines([
            ['product' => $coke, 'quantity' => '2'], ['product' => $service, 'quantity' => '5'], ['product' => $coke, 'quantity' => '1.5'],
        ]);

        $this->assertSame(['a'], array_keys($demands));
        $this->assertSame('3.500', $demands['a']['required']);
    }

    public function test_a_missing_balance_counts_as_zero_and_exactly_enough_is_not_short(): void
    {
        $resolver = new StockDemandResolver;
        $demands = $resolver->fromLines([
            ['product' => $this->product('a', 'Coke'), 'quantity' => '3'],
            ['product' => $this->product('b', 'Chips'), 'quantity' => '1'],
            ['product' => $this->product('c', 'Gum'), 'quantity' => '2'],
        ]);

        $short = $resolver->shortfalls($demands, ['a' => '3.000', 'c' => '1.500']);

        $this->assertSame(['b', 'c'], array_column($short, 'product_id'));
        $this->assertSame(['0.000', '1.500'], array_column($short, 'available'));
        $this->assertSame(['1.000', '2.000'], array_column($short, 'required'));
        $this->assertSame(['PRODUCT', 'RUNNING', null], [$short[0]['kind'], $short[0]['mode'], $short[0]['stock_date']]);
    }

    public function test_a_negative_balance_is_short_by_more_than_the_sale(): void
    {
        $resolver = new StockDemandResolver;
        $demands = $resolver->fromLines([['product' => $this->product('a', 'Coke'), 'quantity' => '1']]);

        $this->assertSame('-4.000', $resolver->shortfalls($demands, ['a' => '-4'])[0]['available']);
    }

    public function test_the_hash_ignores_order_and_available_but_changes_with_the_cart(): void
    {
        $resolver = new StockDemandResolver;
        $line = fn (string $id, string $required, string $available = '0.000') => ['kind' => 'PRODUCT', 'product_id' => $id, 'stock_date' => null, 'required' => $required, 'available' => $available];

        $this->assertSame($resolver->hash([$line('a', '2.000'), $line('b', '1.000')]), $resolver->hash([$line('b', '1.000', '5.000'), $line('a', '2.000', '1.000')]));
        $this->assertNotSame($resolver->hash([$line('a', '2.000')]), $resolver->hash([$line('a', '3.000')]));
        $this->assertNotSame($resolver->hash([$line('a', '2.000')]), $resolver->hash([$line('a', '2.000'), $line('b', '1.000')]));
    }

    public function test_the_threshold_is_the_products_own_level_even_zero_else_the_store_default(): void
    {
        $rules = new StockRules(true, true, 3);

        $this->assertSame(3, $rules->thresholdFor(null));
        $this->assertSame(10, $rules->thresholdFor(10));
        $this->assertSame(0, $rules->thresholdFor(0));
        $this->assertTrue($rules->overrideAvailable());
        $this->assertFalse((new StockRules(false, true))->overrideAvailable(), 'nothing to override while blocking is off');
        $this->assertFalse((new StockRules(true, false))->overrideAvailable());
        $this->assertFalse((new StockRules)->blockSaleWithoutStock);
    }
}

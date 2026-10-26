<?php

namespace Tests\Unit\Services\Inventory;

use App\Domain\Money;
use App\Models\Dish;
use App\Models\Product;
use App\Services\Checkout\ResolvedMealLine;
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

    private function dish(string $id, string $poolId, string $name): Dish
    {
        $dish = new Dish(['name' => $name, 'stock_mode' => 'DAILY', 'stock_product_id' => $poolId]);
        $dish->id = $id;

        return $dish;
    }

    private function meal(Product $backing, array $selections): ResolvedMealLine
    {
        return new ResolvedMealLine($backing, Money::fromApiString('68.00'), 'Meal', 'meal-1', 'Meal', null, $selections);
    }

    private function selection(string $dishId, string $name, string $poolId, ?string $portion): array
    {
        return ['group_name' => 'Viand', 'option_label' => $name, 'dish_id' => $dishId, 'dish_name' => $name, 'dish_category_name' => 'Beef', 'price' => null, 'sort_order' => 0, 'stock_product_id' => $portion === null ? null : $poolId, 'stock_portion' => $portion];
    }

    public function test_an_ala_carte_product_draws_quantity_times_portion_from_its_dishs_pool_and_never_from_itself(): void
    {
        $ala = $this->product('ala', 'Nilaga (ala carte)');
        $ala->stock_mode = 'DAILY';
        $ala->dish_id = 'dish-n';
        $ala->stock_portion = '2.000';
        $ala->setRelation('dish', $this->dish('dish-n', 'pool-n', 'Beef nilaga'));

        $lines = (new StockDemandResolver)->linesFor([['product' => $ala, 'quantity' => '3']], '2026-10-27');

        $this->assertCount(1, $lines);
        $this->assertSame(['pool-n', '2026-10-27', '6.000', 'DISH', '2.000'], [$lines[0]['product_id'], $lines[0]['stock_date'], $lines[0]['quantity'], $lines[0]['kind'], $lines[0]['portion']]);
    }

    public function test_a_meal_a_combo_side_and_an_ala_carte_portion_of_one_dish_pool_into_one_demand(): void
    {
        $resolver = new StockDemandResolver;
        $backing = $this->product('backing', 'Standard Meal', tracked: false);
        $ala = $this->product('ala', 'Nilaga (ala carte)');
        $ala->stock_mode = 'DAILY';
        $ala->dish_id = 'dish-n';
        $ala->stock_portion = '2.000';
        $ala->setRelation('dish', $this->dish('dish-n', 'pool-n', 'Beef nilaga'));
        $rice = $this->meal($this->product('rice', 'Whole rice', tracked: false), [['group_name' => 'Rice', 'option_label' => 'Whole rice', 'dish_id' => null, 'dish_name' => null, 'dish_category_name' => null, 'price' => null, 'sort_order' => 0, 'stock_product_id' => null, 'stock_portion' => null]]);

        $specs = [
            ['product' => $backing, 'quantity' => '1', 'meal' => $this->meal($backing, [$this->selection('dish-n', 'Beef nilaga', 'pool-n', '1.000')])],
            ['product' => $backing, 'quantity' => '1', 'meal' => $this->meal($backing, [$this->selection('dish-n', 'Beef nilaga', 'pool-n', '1.000'), $this->selection('dish-p', 'Pinakbet', 'pool-p', '1.000')])],
            ['product' => $ala, 'quantity' => '1'],
            ['product' => $rice->product, 'quantity' => '1', 'meal' => $rice],
        ];
        $demands = $resolver->demandsFrom($resolver->linesFor($specs, '2026-10-27'));

        $this->assertSame(['pool-n@2026-10-27', 'pool-p@2026-10-27'], array_keys($demands));
        $this->assertSame('4.000', $demands['pool-n@2026-10-27']['required'], '1 + 1 + 2');
        $this->assertSame('1.000', $demands['pool-p@2026-10-27']['required']);
        $this->assertSame(['DISH', 'DAILY', 'Beef nilaga'], [$demands['pool-n@2026-10-27']['kind'], $demands['pool-n@2026-10-27']['mode'], $demands['pool-n@2026-10-27']['name']]);

        $short = $resolver->shortfalls($demands, ['pool-n@2026-10-27' => '4.000', 'pool-p@2026-10-27' => '0.500']);
        $this->assertSame(['pool-p'], array_column($short, 'product_id'));
        $this->assertSame('0.500', $short[0]['available']);
    }

    public function test_a_dish_that_does_not_count_daily_stock_draws_nothing_and_a_plain_daily_product_is_dated(): void
    {
        $resolver = new StockDemandResolver;
        $backing = $this->product('backing', 'Standard Meal', tracked: false);
        $snack = $this->product('snack', 'Siopao');
        $snack->stock_mode = 'DAILY';

        $lines = $resolver->linesFor([
            ['product' => $backing, 'quantity' => '1', 'meal' => $this->meal($backing, [$this->selection('dish-x', 'Plain', 'pool-x', null)])],
            ['product' => $snack, 'quantity' => '2'],
        ], '2026-10-27');

        $this->assertSame(['NONE', 'DAILY'], array_column($lines, 'mode'));
        $this->assertNull($lines[0]['stock_date']);
        $this->assertSame('2026-10-27', $lines[1]['stock_date']);
        $this->assertSame(['snack@2026-10-27'], array_keys($resolver->demandsFrom($lines)));
    }
}

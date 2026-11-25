<?php

namespace Tests\Database;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * A sale's lines are returned in `line_number` order, whatever order the database happens to hold them in.
 */
class SaleItemOrderHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    public function test_sale_lines_are_returned_in_line_number_order_even_when_stored_out_of_order(): void
    {
        $w = $this->world();
        $third = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '20.00', 'cost' => '10.00']);
        $payload = [
            'items' => [
                ['product_id' => $w['product']->id, 'quantity' => '1'],
                ['product_id' => $w['product2']->id, 'quantity' => '1'],
                ['product_id' => $third->id, 'quantity' => '1'],
            ],
            'payments' => [['method' => 'CASH', 'amount' => '170.00']],
        ];
        $key = $this->key();
        $saleId = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', $payload, $key)->assertStatus(201)->json('id');

        // PostgreSQL writes an updated row after the others, so rewriting lines 2 then 1 (with unchanged values) stores them as 3, 2, 1.
        foreach ([2, 1] as $line) {
            DB::update('update sale_items set line_number = line_number where sale_id = ? and line_number = ?', [$saleId, $line]);
        }
        // Through the (sale_id, line_number) index the rows would come back in order by luck of the query plan. This turns index
        // plans off for this test's rolled-back transaction, so an unordered query returns the rows as they are stored.
        foreach (['enable_indexscan', 'enable_indexonlyscan', 'enable_bitmapscan'] as $plan) {
            DB::statement("set local {$plan} = off");
        }
        $stored = array_column(DB::select('select line_number from sale_items where sale_id = ?', [$saleId]), 'line_number');
        $this->assertSame([3, 2, 1], $stored, 'The lines must be stored out of order for this test to prove anything.');

        $read = $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$saleId}")->assertOk();
        $this->assertSame([1, 2, 3], $read->json('items.*.line_number'));

        $replay = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', $payload, $key)->assertStatus(201)->assertJsonPath('id', $saleId);
        $this->assertSame([1, 2, 3], $replay->json('items.*.line_number'));
    }
}

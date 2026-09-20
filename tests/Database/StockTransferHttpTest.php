<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 25 stock transfers (stockTransferCreate/List/Get): an immediate move between two locations of the same
 * store, written as one TRANSFER_OUT and one TRANSFER_IN per product in a single transaction. Terminal-scoped and
 * idempotent like every stock write. A location of another store is simply not found.
 */
class StockTransferHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const BASE = '/api/v1/inventory/transfers';

    private function stock(array $w, Product $product, string $quantity): void
    {
        $this->asUser($w['manager'], $w['enroll1'])->postJson('/api/v1/inventory/receipts', [
            'product_id' => $product->id, 'quantity' => $quantity, 'movement_type' => 'OPENING_STOCK',
        ], $this->key())->assertStatus(201);
    }

    private function backroom(array $w): InventoryLocation
    {
        return InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId'], 'name' => 'Backroom']);
    }

    /** @param  list<array{0: Product, 1: string}>  $items */
    private function transfer(array $w, string $from, string $to, array $items, array $extra = [], ?array $key = null, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $w['manager'], $w['enroll1'])->postJson(self::BASE, [
            'from_location_id' => $from,
            'to_location_id' => $to,
            'items' => array_map(fn (array $item) => ['product_id' => $item[0]->id, 'quantity' => $item[1]], $items),
        ] + $extra, $key ?? $this->key());
    }

    private function balance(Product $product, string $locationId): ?string
    {
        return StockBalance::where('product_id', $product->id)->where('location_id', $locationId)->first()?->quantity_on_hand;
    }

    private function assertLedgerMatchesBalances(): void
    {
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();
    }

    // ---------------------------------------------------------------- creating

    public function test_a_transfer_moves_stock_between_two_locations_and_writes_both_legs(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');

        $response = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '4']], ['note' => '  Restock the counter  '])->assertStatus(201);

        $response->assertJson([
            'from_location_id' => $w['location']->id, 'from_location_name' => $w['location']->name,
            'to_location_id' => $back->id, 'to_location_name' => 'Backroom',
            'note' => 'Restock the counter', 'terminal_id' => $w['t1']->id, 'created_by' => $w['manager']->id, 'lines_count' => 1,
        ]);
        $this->assertSame('4.000', $response->json('lines.0.quantity'));
        $this->assertSame($w['product']->sku, $response->json('lines.0.sku'));
        $this->assertSame('6.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('4.000', $this->balance($w['product'], $back->id));

        $legs = StockMovement::where('reference_type', 'stock_transfer')->where('reference_id', $response->json('id'))->get()->keyBy('movement_type');
        $this->assertCount(2, $legs);
        $this->assertSame($w['location']->id, $legs['TRANSFER_OUT']->location_id);
        $this->assertSame($back->id, $legs['TRANSFER_IN']->location_id);
        foreach ($legs as $leg) {
            $this->assertSame('4.000', $leg->quantity);
            $this->assertSame($w['product']->id, $leg->product_id);
            $this->assertSame($w['t1']->id, $leg->terminal_id);
            $this->assertSame('Restock the counter', $leg->reason);
        }
        $this->assertLedgerMatchesBalances();
    }

    public function test_a_transfer_of_several_products_is_one_document_and_total_stock_never_changes(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $this->stock($w, $w['product2'], '3.5');
        $totalBefore = StockBalance::sum('quantity_on_hand');

        $response = $this->transfer($w, $w['location']->id, $back->id, [[$w['product2'], '1.25'], [$w['product'], '10']])->assertStatus(201);

        $this->assertSame(2, $response->json('lines_count'));
        $this->assertSame(1, StockTransfer::count());
        $this->assertSame(4, StockMovement::where('reference_id', $response->json('id'))->count());
        $this->assertSame('0.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('10.000', $this->balance($w['product'], $back->id));
        $this->assertSame('2.250', $this->balance($w['product2'], $w['location']->id));
        $this->assertSame('1.250', $this->balance($w['product2'], $back->id));
        $this->assertEquals($totalBefore, StockBalance::sum('quantity_on_hand'));
        $this->assertLedgerMatchesBalances();
    }

    public function test_a_transfer_is_audited_with_what_moved(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');

        $id = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '4']])->assertStatus(201)->json('id');

        $audit = AuditEvent::where('event_type', 'STOCK_TRANSFERRED')->firstOrFail();
        $this->assertSame('stock_transfer', $audit->entity_type);
        $this->assertSame($id, $audit->entity_id);
        $this->assertSame($w['manager']->id, $audit->actor_user_id);
        $this->assertSame($w['t1']->id, $audit->terminal_id);
        $this->assertEquals([
            'from_location_id' => $w['location']->id,
            'to_location_id' => $back->id,
            'items' => [['product_id' => $w['product']->id, 'sku' => $w['product']->sku, 'quantity' => '4.000']],
        ], $audit->after_metadata);
    }

    public function test_the_source_may_go_below_zero_like_every_other_outflow(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '2');

        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '5']])->assertStatus(201);

        $this->assertSame('-3.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('5.000', $this->balance($w['product'], $back->id));
        $this->assertLedgerMatchesBalances();
    }

    public function test_stock_can_be_moved_back_which_is_how_a_wrong_transfer_is_corrected(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '4']])->assertStatus(201);

        $this->transfer($w, $back->id, $w['location']->id, [[$w['product'], '4']])->assertStatus(201);

        $this->assertSame('10.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('0.000', $this->balance($w['product'], $back->id));
        $this->assertSame(2, StockTransfer::count());
    }

    // -------------------------------------------------------------- validation

    public function test_the_two_locations_must_differ_and_belong_to_this_store(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $foreign = InventoryLocation::factory()->create(['store_id' => Store::factory()->create()->id]);
        $this->stock($w, $w['product'], '10');

        $this->transfer($w, $w['location']->id, $w['location']->id, [[$w['product'], '1']])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        foreach ([[$w['location']->id, $foreign->id], [$foreign->id, $back->id], [$w['location']->id, (string) Str::uuid()]] as [$from, $to]) {
            $this->transfer($w, $from, $to, [[$w['product'], '1']])->assertStatus(404)->assertJson(['error' => ['code' => 'INVENTORY_LOCATION_NOT_FOUND']]);
        }

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame('10.000', $this->balance($w['product'], $w['location']->id));
        $this->assertNull($foreign->stockMovements()->first(), 'nothing was written to another store');
    }

    public function test_items_are_validated(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $id = $w['product']->id;
        $post = fn (array $body) => $this->asUser($w['manager'], $w['enroll1'])->postJson(self::BASE, ['from_location_id' => $w['location']->id, 'to_location_id' => $back->id] + $body, $this->key());

        foreach ([
            [],
            ['items' => []],
            ['items' => [['product_id' => $id]]],
            ['items' => [['product_id' => $id, 'quantity' => '0']]],
            ['items' => [['product_id' => $id, 'quantity' => '-1']]],
            ['items' => [['product_id' => $id, 'quantity' => '1.2345']]],
            ['items' => [['product_id' => $id, 'quantity' => 'abc']]],
            ['items' => [['product_id' => 'nope', 'quantity' => '1']]],
            ['items' => [['product_id' => $id, 'quantity' => '1'], ['product_id' => $id, 'quantity' => '2']]],
        ] as $body) {
            $post($body)->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->asUser($w['manager'], $w['enroll1'])->postJson(self::BASE, ['to_location_id' => $back->id, 'items' => [['product_id' => $id, 'quantity' => '1']]], $this->key())->assertStatus(422);
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_only_tracked_products_of_this_store_can_move_and_a_bad_item_moves_nothing(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $untracked = Product::factory()->create(['store_id' => $w['storeId'], 'track_inventory' => false]);
        $foreign = Product::factory()->create();

        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1'], [$untracked, '1']])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1'], [$foreign, '1']])->assertStatus(404)->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame('10.000', $this->balance($w['product'], $w['location']->id));
        $this->assertNull($this->balance($w['product'], $back->id), 'the first item was not moved either: the request is all or nothing');
    }

    // ------------------------------------------------------ idempotency, access

    public function test_a_retried_transfer_moves_stock_once(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $key = $this->key();

        $first = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '4']], [], $key)->assertStatus(201);
        $retry = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '4']], [], $key)->assertStatus(201);

        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame(1, StockTransfer::count());
        $this->assertSame('6.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('4.000', $this->balance($w['product'], $back->id));

        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '5']], [], $key)->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $this->assertSame(1, StockTransfer::count());
    }

    public function test_creating_needs_a_key_a_terminal_and_the_capability(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $body = ['from_location_id' => $w['location']->id, 'to_location_id' => $back->id, 'items' => [['product_id' => $w['product']->id, 'quantity' => '1']]];

        $this->asUser($w['manager'], $w['enroll1'])->postJson(self::BASE, $body)->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
        $this->asUser($w['manager'])->postJson(self::BASE, $body, $this->key())->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1']], [], null, $w['cashier'])->assertStatus(403);

        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->postJson(self::BASE, $body, $this->key())->assertStatus(401);

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame('10.000', $this->balance($w['product'], $w['location']->id));
    }

    public function test_an_admin_may_transfer_too(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');

        $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1']], [], null, $w['admin'])->assertStatus(201);
    }

    // ------------------------------------------------------------------- reads

    public function test_the_list_is_newest_first_with_filters_and_line_counts(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $shed = InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId'], 'name' => 'Shed']);
        $this->stock($w, $w['product'], '10');
        $this->stock($w, $w['product2'], '10');
        $first = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1'], [$w['product2'], '1']])->assertStatus(201)->json('id');
        $second = $this->transfer($w, $w['location']->id, $shed->id, [[$w['product2'], '2']])->assertStatus(201)->json('id');
        $list = fn (string $query = '') => $this->asUser($w['manager'])->getJson(self::BASE.$query);

        $all = $list()->assertOk();
        $this->assertSame([$second, $first], array_column($all->json('data'), 'id'));
        $this->assertSame(2, $all->json('meta.total'));
        $this->assertSame([1, 2], array_column($all->json('data'), 'lines_count'));
        $this->assertArrayNotHasKey('lines', $all->json('data.0'), 'the list carries counts, the detail carries the lines');

        $this->assertSame([$first], array_column($list("?location_id={$back->id}")->json('data'), 'id'), 'either side of the move matches');
        $this->assertSame([$second, $first], array_column($list("?location_id={$w['location']->id}")->json('data'), 'id'));
        $this->assertSame([$first], array_column($list("?product_id={$w['product']->id}")->json('data'), 'id'));
        $this->assertSame([$second, $first], array_column($list("?product_id={$w['product2']->id}")->json('data'), 'id'));
        $this->assertSame([$second, $first], array_column($list('?from='.now()->toDateString().'&to='.now()->toDateString())->json('data'), 'id'));
        $this->assertSame([], $list('?from='.now()->addDay()->toDateString())->json('data'));
        $this->assertSame([], $list('?location_id=not-a-uuid')->json('data'));
        $this->assertSame([], $list('?product_id=not-a-uuid')->json('data'));
    }

    public function test_the_detail_lists_the_lines_by_product_name(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $apple = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Apple']);
        $zebra = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Zebra']);
        $this->stock($w, $apple, '5');
        $this->stock($w, $zebra, '5');
        $id = $this->transfer($w, $w['location']->id, $back->id, [[$zebra, '1'], [$apple, '2']])->assertStatus(201)->json('id');

        $detail = $this->asUser($w['manager'])->getJson(self::BASE."/{$id}")->assertOk();

        $this->assertSame(['Apple', 'Zebra'], array_column($detail->json('lines'), 'product_name'));
        $this->assertSame(['2.000', '1.000'], array_column($detail->json('lines'), 'quantity'));
    }

    public function test_another_stores_transfer_is_not_found_and_the_reads_need_stock_adjust(): void
    {
        $w = $this->world();
        $back = $this->backroom($w);
        $this->stock($w, $w['product'], '10');
        $id = $this->transfer($w, $w['location']->id, $back->id, [[$w['product'], '1']])->assertStatus(201)->json('id');
        $stranger = User::factory()->admin()->create(['store_id' => Terminal::factory()->create()->store_id]);

        $this->asUser($stranger)->getJson(self::BASE."/{$id}")->assertStatus(404)->assertJson(['error' => ['code' => 'STOCK_TRANSFER_NOT_FOUND']]);
        $this->assertSame([], $this->asUser($stranger)->getJson(self::BASE)->json('data'));
        $this->asUser($w['manager'])->getJson(self::BASE.'/'.Str::uuid())->assertStatus(404)->assertJson(['error' => ['code' => 'STOCK_TRANSFER_NOT_FOUND']]);

        $this->asUser($w['cashier'])->getJson(self::BASE)->assertStatus(403);
        $this->asUser($w['cashier'])->getJson(self::BASE."/{$id}")->assertStatus(403);
        $this->asUser($w['admin'])->getJson(self::BASE)->assertOk();
    }
}

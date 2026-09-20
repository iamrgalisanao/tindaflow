<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 25 stock counts (stockCountCreate/List/Get, stockCountLinesRecord, stockCountLineRemove, stockCountPost,
 * stockCountCancel). A count belongs to one location, records what was found beside what the ledger expected at
 * that moment, and posting turns the variances into adjustment movements through StockLedger. Uncounted products
 * are never assumed to be zero, and sales made while people count are never overwritten.
 */
class StockCountHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const BASE = '/api/v1/inventory/counts';

    /** Puts stock on hand through the real receipt endpoint (manager at the enrolled terminal). */
    private function stock(array $w, Product $product, string $quantity): void
    {
        $this->asUser($w['manager'], $w['enroll1'])->postJson('/api/v1/inventory/receipts', [
            'product_id' => $product->id, 'quantity' => $quantity, 'movement_type' => 'OPENING_STOCK',
        ], $this->key())->assertStatus(201);
    }

    /** @return array<string, mixed> the started count */
    private function start(array $w, array $body = [], ?User $as = null): array
    {
        return $this->asUser($as ?? $w['manager'])->postJson(self::BASE, $body)->assertStatus(201)->json();
    }

    /** @param  list<array{0: Product, 1: string}>  $counted */
    private function record(array $w, string $countId, array $counted, ?User $as = null): TestResponse
    {
        $lines = array_map(fn (array $line) => ['product_id' => $line[0]->id, 'counted_quantity' => $line[1]], $counted);

        return $this->asUser($as ?? $w['manager'])->putJson(self::BASE."/{$countId}/lines", ['lines' => $lines]);
    }

    private function postCount(array $w, string $countId, ?array $key = null, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $w['manager'], $w['enroll1'])->postJson(self::BASE."/{$countId}/post", [], $key ?? $this->key());
    }

    private function balance(Product $product, ?string $locationId = null): ?string
    {
        return StockBalance::where('product_id', $product->id)->when($locationId, fn ($q) => $q->where('location_id', $locationId))->first()?->quantity_on_hand;
    }

    private function assertLedgerMatchesBalances(): void
    {
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();
    }

    // ---------------------------------------------------------------- starting

    public function test_starting_a_count_creates_an_open_count_at_the_default_location(): void
    {
        $w = $this->world();

        $count = $this->start($w, ['note' => '  Monthly shelf count  ']);

        $this->assertSame('OPEN', $count['status']);
        $this->assertSame($w['location']->id, $count['location_id']);
        $this->assertSame($w['location']->name, $count['location_name']);
        $this->assertSame('Monthly shelf count', $count['note']);
        $this->assertSame($w['manager']->id, $count['created_by']);
        $this->assertSame(0, $count['lines_count']);
        $this->assertSame([], $count['lines']);
        $this->assertNull($count['posted_at']);
    }

    public function test_a_count_can_be_started_at_another_location_of_the_same_store_and_not_at_anyone_elses(): void
    {
        $w = $this->world();
        $backroom = InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId'], 'name' => 'Backroom']);
        $foreign = InventoryLocation::factory()->create(['store_id' => Store::factory()->create()->id]);

        $count = $this->start($w, ['location_id' => $backroom->id]);
        $this->assertSame($backroom->id, $count['location_id']);
        $this->assertSame('Backroom', $count['location_name']);

        foreach ([$foreign->id, (string) Str::uuid()] as $missing) {
            $this->asUser($w['manager'])->postJson(self::BASE, ['location_id' => $missing])
                ->assertStatus(404)->assertJson(['error' => ['code' => 'INVENTORY_LOCATION_NOT_FOUND']]);
        }
        $this->asUser($w['manager'])->postJson(self::BASE, ['location_id' => 'nope'])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_a_location_has_at_most_one_count_in_progress(): void
    {
        $w = $this->world();
        $backroom = InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId']]);
        $first = $this->start($w);

        $this->asUser($w['admin'])->postJson(self::BASE, [])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'STOCK_COUNT_ALREADY_OPEN', 'details' => ['stock_count_id' => $first['id'], 'inventory_location_id' => $w['location']->id]]]);

        // another location is independent, and cancelling frees the first one
        $this->start($w, ['location_id' => $backroom->id]);
        $this->asUser($w['manager'])->postJson(self::BASE."/{$first['id']}/cancel")->assertOk();
        $this->assertSame('OPEN', $this->start($w)['status']);
        $this->assertSame(1, StockCount::where('location_id', $w['location']->id)->where('status', 'OPEN')->count());
    }

    // ------------------------------------------------------------------- lines

    public function test_a_line_remembers_what_the_ledger_expected_and_its_variance(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);

        $body = $this->record($w, $count['id'], [[$w['product'], '8'], [$w['product2'], '3.5']])->assertOk()->json();

        $this->assertSame(2, $body['lines_count']);
        $lines = collect($body['lines'])->keyBy('product_id');
        $this->assertSame('8.000', $lines[$w['product']->id]['counted_quantity']);
        $this->assertSame('10.000', $lines[$w['product']->id]['expected_quantity']);
        $this->assertSame('-2.000', $lines[$w['product']->id]['variance']);
        $this->assertSame('0.000', $lines[$w['product2']->id]['expected_quantity'], 'a product that never moved was expected to be zero');
        $this->assertSame('3.500', $lines[$w['product2']->id]['variance']);
        $this->assertSame($w['product']->sku, $lines[$w['product']->id]['sku']);
        $this->assertNull($lines[$w['product']->id]['stock_movement_id']);
        $this->assertEquals(['lines_counted' => 2, 'lines_with_variance' => 2, 'units_over' => '3.500', 'units_short' => '2.000'], $body['summary']);
    }

    public function test_counting_a_product_again_replaces_its_line_and_reads_the_expected_quantity_again(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();

        $this->stock($w, $w['product'], '5'); // a delivery lands while people are counting
        $body = $this->record($w, $count['id'], [[$w['product'], '15']])->assertOk()->json();

        $this->assertSame(1, $body['lines_count'], 'one line per product');
        $this->assertSame('15.000', $body['lines'][0]['counted_quantity']);
        $this->assertSame('15.000', $body['lines'][0]['expected_quantity'], 'the recount reflects the shelf as it is now');
        $this->assertSame('0.000', $body['lines'][0]['variance']);
        $this->assertSame(1, StockCountLine::where('stock_count_id', $count['id'])->count());
    }

    public function test_zero_is_a_valid_count_and_a_line_can_be_removed(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '4');
        $count = $this->start($w);

        $body = $this->record($w, $count['id'], [[$w['product'], '0']])->assertOk()->json();
        $this->assertSame('0.000', $body['lines'][0]['counted_quantity']);
        $this->assertSame('-4.000', $body['lines'][0]['variance']);

        $removed = $this->asUser($w['manager'])->deleteJson(self::BASE."/{$count['id']}/lines/{$w['product']->id}")->assertOk()->json();
        $this->assertSame(0, $removed['lines_count']);

        // removing something that is not there is not an error: the count simply does not include it
        $this->asUser($w['manager'])->deleteJson(self::BASE."/{$count['id']}/lines/{$w['product2']->id}")->assertOk();
    }

    public function test_line_input_is_validated(): void
    {
        $w = $this->world();
        $count = $this->start($w);
        $put = fn (array $body) => $this->asUser($w['manager'])->putJson(self::BASE."/{$count['id']}/lines", $body);
        $id = $w['product']->id;

        foreach ([
            [],
            ['lines' => []],
            ['lines' => [['product_id' => $id]]],
            ['lines' => [['product_id' => $id, 'counted_quantity' => '-1']]],
            ['lines' => [['product_id' => $id, 'counted_quantity' => '1.2345']]],
            ['lines' => [['product_id' => $id, 'counted_quantity' => 'abc']]],
            ['lines' => [['product_id' => 'nope', 'counted_quantity' => '1']]],
            ['lines' => [['product_id' => $id, 'counted_quantity' => '1'], ['product_id' => $id, 'counted_quantity' => '2']]],
        ] as $body) {
            $put($body)->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->assertSame(0, StockCountLine::count());
    }

    public function test_only_tracked_products_of_this_store_can_be_counted_and_a_bad_line_saves_nothing(): void
    {
        $w = $this->world();
        $count = $this->start($w);
        $untracked = Product::factory()->create(['store_id' => $w['storeId'], 'track_inventory' => false]);
        $foreign = Product::factory()->create();

        $this->record($w, $count['id'], [[$w['product'], '1'], [$untracked, '1']])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->record($w, $count['id'], [[$w['product'], '1'], [$foreign, '1']])->assertStatus(404)->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);

        $this->assertSame(0, StockCountLine::count(), 'the request is all or nothing');
    }

    public function test_an_inactive_product_can_still_be_counted(): void
    {
        $w = $this->world();
        $inactive = Product::factory()->inactive()->create(['store_id' => $w['storeId']]);
        $count = $this->start($w);

        $this->record($w, $count['id'], [[$inactive, '6']])->assertOk();
    }

    // ----------------------------------------------------------------- posting

    public function test_posting_writes_an_adjustment_for_each_variance_and_nothing_else(): void
    {
        $w = $this->world();
        $matching = Product::factory()->create(['store_id' => $w['storeId']]);
        $uncounted = Product::factory()->create(['store_id' => $w['storeId']]);
        $this->stock($w, $w['product'], '10');
        $this->stock($w, $w['product2'], '2');
        $this->stock($w, $matching, '7');
        $this->stock($w, $uncounted, '9');
        $count = $this->start($w, ['note' => 'Aisle 1']);
        $this->record($w, $count['id'], [[$w['product'], '8'], [$w['product2'], '5.5'], [$matching, '7']])->assertOk();
        $movementsBefore = StockMovement::count();

        $response = $this->postCount($w, $count['id'])->assertOk();

        $response->assertJson(['status' => 'POSTED', 'posted_by' => $w['manager']->id]);
        $this->assertNotNull($response->json('posted_at'));
        $this->assertSame($movementsBefore + 2, StockMovement::count(), 'two variances, one matching line, one uncounted product');

        $short = StockMovement::where('product_id', $w['product']->id)->where('reference_type', 'stock_count')->firstOrFail();
        $this->assertSame('STOCK_ADJUSTMENT_OUT', $short->movement_type);
        $this->assertSame('2.000', $short->quantity);
        $over = StockMovement::where('product_id', $w['product2']->id)->where('reference_type', 'stock_count')->firstOrFail();
        $this->assertSame('STOCK_ADJUSTMENT_IN', $over->movement_type);
        $this->assertSame('3.500', $over->quantity);
        foreach ([$short, $over] as $movement) {
            $this->assertSame($count['id'], $movement->reference_id);
            $this->assertSame($w['location']->id, $movement->location_id);
            $this->assertSame($w['t1']->id, $movement->terminal_id);
            $this->assertSame($w['manager']->id, $movement->created_by);
            $this->assertSame('Stock count', $movement->reason);
        }

        $this->assertSame('8.000', $this->balance($w['product']));
        $this->assertSame('5.500', $this->balance($w['product2']));
        $this->assertSame('7.000', $this->balance($matching));
        $this->assertSame('9.000', $this->balance($uncounted), 'a product nobody counted is left alone, never assumed to be zero');
        $this->assertNotNull(StockCountLine::where('stock_count_id', $count['id'])->where('product_id', $w['product']->id)->value('stock_movement_id'));
        $this->assertNull(StockCountLine::where('stock_count_id', $count['id'])->where('product_id', $matching->id)->value('stock_movement_id'));
        $this->assertLedgerMatchesBalances();
    }

    public function test_posting_is_audited_and_each_adjustment_is_journaled(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $this->stock($w, $w['product2'], '2');
        $count = $this->start($w, ['note' => 'Aisle 1']);
        $this->record($w, $count['id'], [[$w['product'], '8'], [$w['product2'], '5.5']])->assertOk();

        $this->postCount($w, $count['id'])->assertOk();

        $audit = AuditEvent::where('event_type', 'STOCK_COUNT_POSTED')->firstOrFail();
        $this->assertSame('stock_count', $audit->entity_type);
        $this->assertSame($count['id'], $audit->entity_id);
        $this->assertSame($w['manager']->id, $audit->actor_user_id);
        $this->assertSame($w['t1']->id, $audit->terminal_id);
        $this->assertSame('Aisle 1', $audit->reason);
        $this->assertEquals([
            'location_id' => $w['location']->id, 'lines_counted' => 2, 'lines_adjusted' => 2, 'units_found_over' => '3.500', 'units_found_short' => '2.000',
        ], $audit->after_metadata);
        $this->assertSame(2, ElectronicJournalEntry::where('audit_event_id', $audit->id)->where('event_type', 'STOCK_ADJUSTED')->count());
    }

    public function test_sales_made_while_counting_are_never_overwritten(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);

        // the shelf is counted at 8 (two short), and only afterwards two more are sold
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();
        $this->ring($w, [[$w['product'], '2']]);
        $this->assertSame('8.000', $this->balance($w['product']));

        $this->postCount($w, $count['id'])->assertOk();

        $this->assertSame('6.000', $this->balance($w['product']), 'the two sold after counting stay sold; only the two that were short are written off');
        $this->assertLedgerMatchesBalances();
    }

    public function test_a_sale_made_before_the_product_is_counted_is_already_in_the_expected_quantity(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);

        $this->ring($w, [[$w['product'], '2']]);
        $line = $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk()->json('lines.0');

        $this->assertSame('8.000', $line['expected_quantity']);
        $this->assertSame('0.000', $line['variance'], 'no false discrepancy');
        $this->postCount($w, $count['id'])->assertOk();
        $this->assertSame('8.000', $this->balance($w['product']));
    }

    public function test_a_count_of_another_location_changes_only_that_location(): void
    {
        $w = $this->world();
        $backroom = InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId']]);
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w, ['location_id' => $backroom->id]);
        $this->record($w, $count['id'], [[$w['product'], '4']])->assertOk();

        $this->postCount($w, $count['id'])->assertOk();

        $this->assertSame('10.000', $this->balance($w['product'], $w['location']->id));
        $this->assertSame('4.000', $this->balance($w['product'], $backroom->id));
        $this->assertLedgerMatchesBalances();
    }

    public function test_a_count_with_no_lines_cannot_be_posted(): void
    {
        $w = $this->world();
        $count = $this->start($w);

        $this->postCount($w, $count['id'])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);

        $this->assertSame('OPEN', StockCount::findOrFail($count['id'])->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_count_where_everything_matches_posts_without_moving_stock(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '10']])->assertOk();
        $movements = StockMovement::count();

        $this->postCount($w, $count['id'])->assertOk()->assertJson(['status' => 'POSTED']);

        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(0, AuditEvent::where('event_type', 'STOCK_COUNT_POSTED')->firstOrFail()->after_metadata['lines_adjusted']);
    }

    public function test_a_retried_post_returns_the_same_count_and_adjusts_once(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();
        $key = $this->key();

        $first = $this->postCount($w, $count['id'], $key)->assertOk();
        $retry = $this->postCount($w, $count['id'], $key)->assertOk();

        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame(1, StockMovement::where('reference_type', 'stock_count')->count());
        $this->assertSame('8.000', $this->balance($w['product']));
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_COUNT_POSTED')->count());
    }

    public function test_posting_needs_a_key_a_terminal_and_the_capability(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();

        $this->asUser($w['manager'], $w['enroll1'])->postJson(self::BASE."/{$count['id']}/post")->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
        $this->asUser($w['manager'])->postJson(self::BASE."/{$count['id']}/post", [], $this->key())->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->postCount($w, $count['id'], null, $w['cashier'])->assertStatus(403);

        $this->assertSame('OPEN', StockCount::findOrFail($count['id'])->status);
        $this->assertSame('10.000', $this->balance($w['product']));
    }

    public function test_a_posted_count_is_frozen(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();
        $this->postCount($w, $count['id'])->assertOk();

        $frozen = ['error' => ['code' => 'STOCK_COUNT_NOT_OPEN', 'details' => ['status' => 'POSTED']]];
        $this->record($w, $count['id'], [[$w['product'], '1']])->assertStatus(409)->assertJson($frozen);
        $this->asUser($w['manager'])->deleteJson(self::BASE."/{$count['id']}/lines/{$w['product']->id}")->assertStatus(409)->assertJson($frozen);
        $this->asUser($w['manager'])->postJson(self::BASE."/{$count['id']}/cancel")->assertStatus(409)->assertJson($frozen);
        $this->postCount($w, $count['id'])->assertStatus(409)->assertJson($frozen);

        $this->assertSame('8.000', $this->balance($w['product']));
        $this->assertSame(1, StockMovement::where('reference_type', 'stock_count')->count());
    }

    // ------------------------------------------------------------------ cancel

    public function test_cancelling_discards_the_count_without_touching_stock_and_is_audited(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '3']])->assertOk();

        $body = $this->asUser($w['manager'])->postJson(self::BASE."/{$count['id']}/cancel")->assertOk()->json();

        $this->assertSame('CANCELLED', $body['status']);
        $this->assertNotNull($body['cancelled_at']);
        $this->assertSame('10.000', $this->balance($w['product']));
        $audit = AuditEvent::where('event_type', 'STOCK_COUNT_CANCELLED')->firstOrFail();
        $this->assertSame($count['id'], $audit->entity_id);
        $this->assertSame(1, $audit->after_metadata['lines_discarded']);

        $this->record($w, $count['id'], [[$w['product'], '3']])->assertStatus(409)->assertJson(['error' => ['code' => 'STOCK_COUNT_NOT_OPEN', 'details' => ['status' => 'CANCELLED']]]);
        $this->postCount($w, $count['id'])->assertStatus(409);
        $this->asUser($w['manager'])->postJson(self::BASE."/{$count['id']}/cancel")->assertStatus(409);
    }

    // -------------------------------------------------------------------- reads

    public function test_the_list_is_newest_first_filterable_and_carries_line_counts(): void
    {
        $w = $this->world();
        $backroom = InventoryLocation::factory()->notDefault()->create(['store_id' => $w['storeId']]);
        $first = $this->start($w);
        $this->record($w, $first['id'], [[$w['product'], '1'], [$w['product2'], '1']])->assertOk();
        $this->asUser($w['manager'])->postJson(self::BASE."/{$first['id']}/cancel")->assertOk();
        $second = $this->start($w, ['location_id' => $backroom->id]);

        $all = $this->asUser($w['manager'])->getJson(self::BASE)->assertOk();
        $this->assertSame([$second['id'], $first['id']], array_column($all->json('data'), 'id'));
        $this->assertSame(2, $all->json('meta.total'));
        $this->assertSame(2, $all->json('data.1.lines_count'));
        $this->assertArrayNotHasKey('lines', $all->json('data.0'), 'the list carries counts, the detail carries the lines');

        $this->assertSame([$second['id']], array_column($this->asUser($w['manager'])->getJson(self::BASE.'?status=OPEN')->json('data'), 'id'));
        $this->assertSame([$first['id']], array_column($this->asUser($w['manager'])->getJson(self::BASE.'?status=CANCELLED')->json('data'), 'id'));
        $this->assertSame([$second['id']], array_column($this->asUser($w['manager'])->getJson(self::BASE."?location_id={$backroom->id}")->json('data'), 'id'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson(self::BASE.'?status=BOGUS')->json('data'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson(self::BASE.'?location_id=not-a-uuid')->json('data'));
    }

    public function test_the_detail_lists_lines_by_product_name(): void
    {
        $w = $this->world();
        $apple = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Apple']);
        $zebra = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Zebra']);
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$zebra, '1'], [$apple, '2']])->assertOk();

        $lines = $this->asUser($w['manager'])->getJson(self::BASE."/{$count['id']}")->assertOk()->json('lines');

        $this->assertSame(['Apple', 'Zebra'], array_column($lines, 'product_name'));
    }

    // ----------------------------------------------------- isolation and access

    public function test_another_stores_count_is_not_found_everywhere(): void
    {
        $w = $this->world();
        $this->stock($w, $w['product'], '10');
        $count = $this->start($w);
        $this->record($w, $count['id'], [[$w['product'], '8']])->assertOk();
        $otherStore = Store::factory()->create();
        $stranger = User::factory()->admin()->create(['store_id' => $otherStore->id]);
        $missing = ['error' => ['code' => 'STOCK_COUNT_NOT_FOUND']];

        $this->asUser($stranger)->getJson(self::BASE."/{$count['id']}")->assertStatus(404)->assertJson($missing);
        $this->asUser($stranger)->putJson(self::BASE."/{$count['id']}/lines", ['lines' => [['product_id' => $w['product']->id, 'counted_quantity' => '1']]])->assertStatus(404)->assertJson($missing);
        $this->asUser($stranger)->deleteJson(self::BASE."/{$count['id']}/lines/{$w['product']->id}")->assertStatus(404)->assertJson($missing);
        $this->asUser($stranger)->postJson(self::BASE."/{$count['id']}/cancel")->assertStatus(404)->assertJson($missing);
        $this->assertSame([], $this->asUser($stranger)->getJson(self::BASE)->json('data'));

        // an admin of the other store, at a terminal of their own store, cannot post it either
        $foreignTerminal = Terminal::factory()->create(['store_id' => $otherStore->id]);
        $token = $this->asUser($stranger)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $foreignTerminal->id])->json('token');
        $enrolled = $this->asUser($stranger)->postJson('/api/v1/terminal/enroll', ['token' => $token]);
        $this->asUser($stranger, $enrolled)->postJson(self::BASE."/{$count['id']}/post", [], $this->key())->assertStatus(404)->assertJson($missing);

        $this->assertSame('OPEN', StockCount::findOrFail($count['id'])->status);
        $this->assertSame(1, StockCountLine::where('stock_count_id', $count['id'])->count());
        $this->assertSame('10.000', $this->balance($w['product']));
    }

    public function test_every_operation_needs_stock_adjust_and_a_session(): void
    {
        $w = $this->world();
        $count = $this->start($w);
        $lines = ['lines' => [['product_id' => $w['product']->id, 'counted_quantity' => '1']]];

        // a genuine guest: no cookie carried over from the setup above
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE, [])->assertStatus(401);

        $cashier = fn () => $this->asUser($w['cashier']);
        $cashier()->getJson(self::BASE)->assertStatus(403);
        $cashier()->postJson(self::BASE, [])->assertStatus(403);
        $cashier()->getJson(self::BASE."/{$count['id']}")->assertStatus(403);
        $cashier()->putJson(self::BASE."/{$count['id']}/lines", $lines)->assertStatus(403);
        $cashier()->deleteJson(self::BASE."/{$count['id']}/lines/{$w['product']->id}")->assertStatus(403);
        $cashier()->postJson(self::BASE."/{$count['id']}/cancel")->assertStatus(403);

        // an admin and a manager may both count, with no terminal for the drafting steps
        $this->asUser($w['admin'])->getJson(self::BASE)->assertOk();
        $this->asUser($w['manager'])->getJson(self::BASE."/{$count['id']}")->assertOk();
    }
}

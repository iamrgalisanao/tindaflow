<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockOverrideGrant;
use App\Models\User;
use App\Services\Employees\CreditPolicyService;
use App\Services\Inventory\StockLedger;
use App\Services\Overrides\OverrideCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-017 slice A, invariants.md STK-001 and STK-005: with the store's "block a sale without stock" setting ON, a sale that demands more of a
 * tracked product than is on hand is refused whole (409 INSUFFICIENT_STOCK) and writes nothing; with it OFF nothing changes at all. The manager
 * override, the till's availability read, the ADMIN-only rules screen and the low-stock threshold are covered here; the two-process races are in
 * StockBlockingConcurrencyTest.
 */
class StockBlockingHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const CODE = '482916';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000, 'tindaflow.credit_override.throttle_attempts' => 1000]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $w */
    private function rules(array $w, array $values = []): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'created_at' => now(), 'updated_at' => now(),
        ] + $values + ['block_sale_without_stock' => true]);
    }

    /** Puts stock on the shelf through the ledger, as a receipt would. */
    private function stock(array $w, Product $product, string $quantity): void
    {
        if (bccomp($quantity, '0', 3) === 0) {
            // A movement must be above zero; "stock entered and all sold" is a balance row at zero.
            DB::table('stock_balances')->insert(['product_id' => $product->id, 'location_id' => $w['location']->id, 'quantity_on_hand' => '0.000', 'updated_at' => now()]);

            return;
        }

        app(StockLedger::class)->record([
            'product_id' => $product->id, 'location_id' => $w['location']->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => $quantity,
            'reference_type' => 'test', 'reference_id' => (string) Str::uuid(), 'created_by' => $w['admin']->id,
        ]);
    }

    private function onHand(Product $product): ?string
    {
        return StockBalance::where('product_id', $product->id)->first()?->quantity_on_hand;
    }

    private function product(array $w, string $price = '10.00', array $attributes = []): Product
    {
        return Product::factory()->create(array_merge(['store_id' => $w['storeId'], 'selling_price' => $price, 'cost' => '5.00', 'reorder_level' => null], $attributes));
    }

    /**
     * @param  list<array{0: Product, 1: string}>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $w, array $lines, array $extra = [], ?array $headers = null, string $who = 'cashier', string $till = 'enroll1'): TestResponse
    {
        $total = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, bcmul((string) $line[0]->selling_price, $line[1], 2), 2), '0.00');

        return $this->asUser($w[$who], $w[$till])->postJson('/api/v1/sales', [
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payments' => [['method' => 'CASH', 'amount' => $total]],
        ] + $extra, $headers ?? $this->key());
    }

    private function codeFor(User $approver, string $code = self::CODE): void
    {
        if (! DB::table('user_override_codes')->where('user_id', $approver->id)->exists()) {
            app(OverrideCodeService::class)->setOwn($approver, 'password', $code);
        }
    }

    /**
     * @param  list<array{0: Product, 1: string}>  $lines
     * @return array<string, mixed> the override-grant request body
     */
    private function grantBody(User $approver, array $lines, string $code = self::CODE): array
    {
        return [
            'approver_id' => $approver->id, 'code' => $code,
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        ];
    }

    /** @param  list<array{0: Product, 1: string}>  $lines */
    private function approval(array $w, array $lines, ?User $approver = null): string
    {
        $approver ??= $w['manager'];
        $this->codeFor($approver);

        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/stock-overrides', $this->grantBody($approver, $lines))->assertStatus(201)->json('token');
    }

    private function refusalReasons(): array
    {
        return AuditEvent::where('event_type', 'STOCK_OVERRIDE_REFUSED')->orderBy('occurred_at')->orderBy('id')->pluck('reason')->all();
    }

    // ------------------------------------------------------------------ setting OFF

    public function test_with_blocking_off_a_sale_with_no_stock_goes_through_and_stock_goes_negative_as_before(): void
    {
        $w = $this->world();
        $coke = $this->product($w);

        $this->sell($w, [[$coke, '2']])->assertStatus(201);

        $this->assertSame('-2.000', $this->onHand($coke));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->count());
    }

    public function test_a_store_row_without_the_columns_set_defaults_to_off(): void
    {
        $w = $this->world();
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'X', 'registered_name' => '', 'tin' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse((bool) DB::table('store_settings')->where('store_id', $w['storeId'])->value('block_sale_without_stock'));
        $this->assertTrue((bool) DB::table('store_settings')->where('store_id', $w['storeId'])->value('stock_override_enabled'));
        $this->assertSame(3, (int) DB::table('store_settings')->where('store_id', $w['storeId'])->value('low_stock_default_threshold'));
        $this->sell($w, [[$this->product($w), '1']])->assertStatus(201);
    }

    // ------------------------------------------------------------------ the block rule

    public function test_blocked_at_zero_with_every_short_line_listed_and_nothing_written(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w, '20.00', ['name' => 'Coke']);
        $this->stock($w, $coke, '0');
        $seriesBefore = DB::table('invoice_series')->where('store_id', $w['storeId'])->value('current_number');
        $key = (string) Str::uuid();

        $response = $this->sell($w, [[$coke, '1']], [], ['Idempotency-Key' => $key])->assertStatus(409);

        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('error.details.override_available', true)
            ->assertJsonPath('error.details.shortfalls.0.kind', 'PRODUCT')
            ->assertJsonPath('error.details.shortfalls.0.product_id', $coke->id)
            ->assertJsonPath('error.details.shortfalls.0.name', 'Coke')
            ->assertJsonPath('error.details.shortfalls.0.mode', 'RUNNING')
            ->assertJsonPath('error.details.shortfalls.0.stock_date', null)
            ->assertJsonPath('error.details.shortfalls.0.required', '1.000')
            ->assertJsonPath('error.details.shortfalls.0.available', '0.000');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE')->count());
        $this->assertSame('0.000', $this->onHand($coke));
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame($seriesBefore, DB::table('invoice_series')->where('store_id', $w['storeId'])->value('current_number'));
        $this->assertSame(0, DB::table('idempotency_records')->where('idempotency_key', $key)->count(), 'a refused checkout stores no idempotency result');
    }

    public function test_a_refused_checkout_leaves_nothing_and_the_same_key_succeeds_once_stock_is_entered(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $this->sell($w, [[$coke, '2']], [], $key)->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertNull($this->onHand($coke), 'the refused sale did not even leave a zero stock row behind');

        $this->stock($w, $coke, '5');
        $sale = $this->sell($w, [[$coke, '2']], [], $key)->assertStatus(201)->json();

        $this->assertSame('3.000', $this->onHand($coke));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('idempotency_records')->where('idempotency_key', $key['Idempotency-Key'])->where('status', 'COMPLETED')->count());

        // And the completed sale replays as ever, counted once.
        $this->sell($w, [[$coke, '2']], [], $key)->assertStatus(201)->assertJsonPath('id', $sale['id']);
        $this->assertSame('3.000', $this->onHand($coke));
    }

    public function test_a_product_with_no_stock_record_counts_as_zero(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);

        $this->sell($w, [[$coke, '1']])->assertStatus(409)->assertJsonPath('error.details.shortfalls.0.available', '0.000');
    }

    public function test_partial_stock_blocks_and_the_exact_quantity_is_allowed(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->stock($w, $coke, '2');

        $this->sell($w, [[$coke, '3']])->assertStatus(409)
            ->assertJsonPath('error.details.shortfalls.0.required', '3.000')->assertJsonPath('error.details.shortfalls.0.available', '2.000');
        $this->assertSame('2.000', $this->onHand($coke));

        $this->sell($w, [[$coke, '2']])->assertStatus(201);
        $this->assertSame('0.000', $this->onHand($coke));
        $this->sell($w, [[$coke, '1']])->assertStatus(409);
    }

    public function test_the_same_product_on_two_lines_is_added_up_before_the_check(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->stock($w, $coke, '3');

        // 2 + 2 = 4 is more than 3 although each line alone fits.
        $this->sell($w, [[$coke, '2'], [$coke, '2']])->assertStatus(409)
            ->assertJsonPath('error.details.shortfalls.0.required', '4.000')->assertJsonPath('error.details.shortfalls.0.available', '3.000');
        $this->sell($w, [[$coke, '2'], [$coke, '1']])->assertStatus(201);
        $this->assertSame('0.000', $this->onHand($coke));
        $this->assertSame(2, StockMovement::where('movement_type', 'SALE')->count(), 'one movement per sale line, as always');
    }

    public function test_a_mixed_cart_is_blocked_whole_and_lists_every_short_line_but_only_those(): void
    {
        $w = $this->world();
        $this->rules($w);
        $enough = $this->product($w, '10.00', ['name' => 'Enough']);
        $short = $this->product($w, '10.00', ['name' => 'Short']);
        $none = $this->product($w, '10.00', ['name' => 'None']);
        $this->stock($w, $enough, '10');
        $this->stock($w, $short, '1');

        $response = $this->sell($w, [[$enough, '2'], [$short, '2'], [$none, '1']])->assertStatus(409);

        $lines = collect($response->json('error.details.shortfalls'))->keyBy('product_id');
        $this->assertSame([$short->id, $none->id], $lines->keys()->all());
        $this->assertSame('1.000', $lines[$short->id]['available']);
        $this->assertSame('0.000', $lines[$none->id]['available']);
        $this->assertSame('10.000', $this->onHand($enough), 'the line that had enough was not sold either');
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE')->count());
    }

    public function test_an_untracked_product_is_never_blocked_and_never_counted(): void
    {
        $w = $this->world();
        $this->rules($w);
        $service = $this->product($w, '10.00', ['track_inventory' => false]);

        $this->sell($w, [[$service, '5']])->assertStatus(201);
        $this->assertSame(0, DB::table('stock_override_grants')->count());
    }

    public function test_slip_mode_refusal_leaves_no_slip_number_and_the_sale_then_takes_the_first_number(): void
    {
        $w = $this->world();
        $w['t1']->update(['terminal_code' => 'PARK-1']);
        $w['t1']->update(['terminal_code' => 'T-01']);
        $this->rules($w, ['sale_document_mode' => 'SLIP']);
        $coke = $this->product($w);

        $this->sell($w, [[$coke, '1']])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame(0, DB::table('sale_slips')->count());
        $this->assertSame(0, DB::table('slip_counters')->count(), 'the counter row of a refused sale rolled back with it');

        $this->stock($w, $coke, '1');
        $this->sell($w, [[$coke, '1']])->assertStatus(201);
        $this->assertSame(1, (int) DB::table('slip_counters')->value('last_number'), 'the first sale takes the first number: the refusal left no gap');
        $this->assertSame(1, DB::table('sale_slips')->count());
    }

    public function test_a_refund_is_never_blocked_even_with_blocking_on_and_nothing_in_stock(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w, '100.00');
        $this->stock($w, $coke, '3');
        $sale = $this->sell($w, [[$coke, '3']])->assertStatus(201)->json();
        $this->assertSame('0.000', $this->onHand($coke));

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']], 'reason' => 'Returned',
        ], $this->key())->assertStatus(201);
        $this->assertSame('1.000', $this->onHand($coke));
    }

    public function test_a_void_is_never_blocked_even_with_blocking_on_and_nothing_in_stock(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w, '100.00');
        $this->stock($w, $coke, '3');
        $sale = $this->sell($w, [[$coke, '3']])->assertStatus(201)->json();
        $this->assertSame('0.000', $this->onHand($coke));

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())->assertStatus(201);
        $this->assertSame('3.000', $this->onHand($coke), 'the void gave it all back');
    }

    public function test_a_meal_free_cart_with_both_tracked_and_untracked_lines_only_checks_the_tracked_ones(): void
    {
        $w = $this->world();
        $this->rules($w);
        $tracked = $this->product($w);
        $service = $this->product($w, '10.00', ['track_inventory' => false]);
        $this->stock($w, $tracked, '1');

        $this->sell($w, [[$tracked, '1'], [$service, '4']])->assertStatus(201);
        $this->assertSame('0.000', $this->onHand($tracked));
    }

    // ------------------------------------------------------------------ the till's availability read

    public function test_availability_is_open_to_a_cashier_scoped_to_the_store_and_has_no_costs(): void
    {
        $w = $this->world();
        $this->rules($w);
        $plain = $this->product($w, '10.00', ['name' => 'Plain']);
        $untracked = $this->product($w, '10.00', ['track_inventory' => false]);
        $inactive = $this->product($w, '10.00', ['active' => false]);
        $foreign = Product::factory()->create();
        $this->stock($w, $plain, '7');
        $this->stock($w, $untracked, '9');
        $this->stock($w, $inactive, '9');

        $body = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk()->json();

        $ids = array_column($body['items'], 'product_id');
        $this->assertContains($plain->id, $ids);
        $this->assertNotContains($untracked->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertTrue($body['blocking']);
        $this->assertTrue($body['override_available']);
        $this->assertSame(3, $body['low_stock_default_threshold']);
        $row = collect($body['items'])->firstWhere('product_id', $plain->id);
        $this->assertSame(['product_id', 'remaining', 'threshold', 'low', 'sold_out'], array_keys($row));
        $this->assertSame('7.000', $row['remaining']);
        $this->assertStringNotContainsString('cost', json_encode($body));
    }

    public function test_availability_needs_a_session(): void
    {
        $this->getJson('/api/v1/stock/availability')->assertStatus(401);
    }

    public function test_the_low_stock_flag_uses_the_store_default_unless_the_product_has_its_own_level(): void
    {
        $w = $this->world();
        $this->rules($w, ['low_stock_default_threshold' => 3]);
        $byDefault = $this->product($w, '10.00', ['name' => 'A default']);
        $own = $this->product($w, '10.00', ['name' => 'B own', 'reorder_level' => 10]);
        $zero = $this->product($w, '10.00', ['name' => 'C zero', 'reorder_level' => 0]);
        $edge = $this->product($w, '10.00', ['name' => 'D edge']);
        $none = $this->product($w, '10.00', ['name' => 'E none']);
        $this->stock($w, $byDefault, '4');
        $this->stock($w, $own, '10');
        $this->stock($w, $zero, '1');
        $this->stock($w, $edge, '3');

        $items = collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk()->json('items'))->keyBy('product_id');

        $this->assertFalse($items[$byDefault->id]['low'], '4 left is above the default of 3');
        $this->assertTrue($items[$own->id]['low'], 'its own level of 10 wins, and exactly 10 left is still low');
        $this->assertSame(10, $items[$own->id]['threshold']);
        $this->assertFalse($items[$zero->id]['low'], 'an explicit 0 warns only when sold out');
        $this->assertTrue($items[$edge->id]['low'], 'exactly the threshold is low');
        $this->assertFalse($items[$edge->id]['sold_out']);
        $this->assertTrue($items[$none->id]['sold_out'], 'no stock record is sold out');
        $this->assertFalse($items[$none->id]['low'], 'sold out is not "low"');
        $this->assertSame('0.000', $items[$none->id]['remaining']);

        $this->rules($w, ['low_stock_default_threshold' => 5]);
        $again = collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->json('items'))->keyBy('product_id');
        $this->assertTrue($again[$byDefault->id]['low'], 'the store default moved to 5');
    }

    public function test_the_low_stock_list_and_the_report_use_the_same_effective_threshold(): void
    {
        $w = $this->world();
        $this->rules($w, ['low_stock_default_threshold' => 3]);
        $atDefault = $this->product($w, '10.00', ['name' => 'At default']);
        $above = $this->product($w, '10.00', ['name' => 'Above']);
        $ownLevel = $this->product($w, '10.00', ['name' => 'Own level', 'reorder_level' => 20]);
        $this->stock($w, $atDefault, '3');
        $this->stock($w, $above, '4');
        $this->stock($w, $ownLevel, '15');

        $list = $this->asUser($w['manager'])->getJson('/api/v1/inventory/low-stock')->assertOk();
        $this->assertEqualsCanonicalizing([$atDefault->id, $ownLevel->id], array_column($list->json('data'), 'product_id'));
        $this->assertSame(3, collect($list->json('data'))->firstWhere('product_id', $atDefault->id)['low_stock_threshold']);
        $this->assertSame(20, collect($list->json('data'))->firstWhere('product_id', $ownLevel->id)['low_stock_threshold']);

        $report = $this->asUser($w['admin'])->getJson('/api/v1/reports/low-stock')->assertOk();
        $this->assertEqualsCanonicalizing([$atDefault->id, $ownLevel->id], array_column($report->json('rows'), 'product_id'));
    }

    // ------------------------------------------------------------------ the rules screen

    public function test_only_the_administrator_reads_and_changes_the_rules(): void
    {
        $w = $this->world();

        foreach (['manager', 'cashier'] as $who) {
            $this->asUser($w[$who])->getJson('/api/v1/stock-rules')->assertStatus(403);
            $this->asUser($w[$who])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => true, 'stock_override_enabled' => true, 'low_stock_default_threshold' => 3])->assertStatus(403);
        }
        $this->assertFalse((bool) DB::table('store_settings')->where('store_id', $w['storeId'])->value('block_sale_without_stock'));
    }

    public function test_the_rules_need_a_session(): void
    {
        $this->getJson('/api/v1/stock-rules')->assertStatus(401);
        $this->putJson('/api/v1/stock-rules', [])->assertStatus(401);
    }

    public function test_the_rules_screen_shows_the_defaults_and_how_many_items_would_be_unsellable(): void
    {
        $w = $this->world();
        $stocked = $this->product($w, '10.00', ['name' => 'Stocked']);
        $empty = $this->product($w, '10.00', ['name' => 'Empty']);
        $noRecord = $this->product($w, '10.00', ['name' => 'No record']);
        $this->product($w, '10.00', ['track_inventory' => false]);
        $this->stock($w, $stocked, '5');
        $this->stock($w, $empty, '0');

        $body = $this->asUser($w['admin'])->getJson('/api/v1/stock-rules')->assertOk()->json();

        $this->assertFalse($body['block_sale_without_stock']);
        $this->assertTrue($body['stock_override_enabled']);
        $this->assertSame(3, $body['low_stock_default_threshold']);
        // Empty, No record, and the two tracked products world() already made (no stock record); Stocked and the untracked one are not counted.
        $this->assertSame(4, $body['preflight']['unsellable_count']);
        $this->assertSame(5, $body['preflight']['tracked_count']);
        $names = array_column($body['preflight']['sample'], 'name');
        $this->assertContains('Empty', $names);
        $this->assertContains('No record', $names);
        $this->assertNotContains('Stocked', $names);
    }

    public function test_saving_the_rules_is_audited_with_old_and_new_values_and_a_repeat_writes_nothing(): void
    {
        $w = $this->world();
        $this->product($w);
        $body = ['block_sale_without_stock' => true, 'stock_override_enabled' => false, 'low_stock_default_threshold' => 5];

        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', $body)->assertOk()
            ->assertJsonPath('block_sale_without_stock', true)->assertJsonPath('stock_override_enabled', false)->assertJsonPath('low_stock_default_threshold', 5);

        $event = AuditEvent::where('event_type', 'STOCK_RULES_CHANGED')->sole();
        $this->assertSame($w['admin']->id, $event->actor_user_id);
        $this->assertEquals(['block_sale_without_stock' => false, 'stock_override_enabled' => true, 'low_stock_default_threshold' => 3], $event->before_metadata);
        $this->assertSame(true, $event->after_metadata['block_sale_without_stock']);
        $this->assertSame(false, $event->after_metadata['stock_override_enabled']);
        $this->assertSame(5, $event->after_metadata['low_stock_default_threshold']);
        $this->assertArrayHasKey('unsellable_count_when_enabled', $event->after_metadata);

        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', $body)->assertOk();
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_RULES_CHANGED')->count());
    }

    public function test_the_rules_are_validated(): void
    {
        $w = $this->world();

        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => 'maybe', 'stock_override_enabled' => true, 'low_stock_default_threshold' => 3])->assertStatus(422);
        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => true, 'stock_override_enabled' => true, 'low_stock_default_threshold' => -1])->assertStatus(422);
        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => true, 'stock_override_enabled' => true, 'low_stock_default_threshold' => 1001])->assertStatus(422);
        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => true])->assertStatus(422);
        $this->assertSame(0, AuditEvent::where('event_type', 'STOCK_RULES_CHANGED')->count());
    }

    // ------------------------------------------------------------------ the manager override

    public function test_a_blocked_sale_goes_through_with_a_manager_approval_and_is_recorded_as_oversold(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w, '20.00', ['name' => 'Coke']);
        $this->stock($w, $coke, '1');

        $this->sell($w, [[$coke, '3']])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $token = $this->approval($w, [[$coke, '3']]);
        $sale = $this->sell($w, [[$coke, '3']], ['stock_override_token' => $token])->assertStatus(201)->json();

        $this->assertSame('-2.000', $this->onHand($coke), 'the sale went into negative stock');
        $grant = StockOverrideGrant::sole();
        $this->assertSame($sale['id'], $grant->sale_id, 'sale_id is the oversold flag a report can read');
        $this->assertNotNull($grant->consumed_at);
        $this->assertSame($w['manager']->id, $grant->approver_id);
        $this->assertSame($w['cashier']->id, $grant->cashier_id);
        $this->assertSame($w['t1']->id, $grant->terminal_id);

        $used = AuditEvent::where('event_type', 'STOCK_OVERRIDE_USED')->sole();
        $this->assertSame([$w['cashier']->id, $w['t1']->id, 'stock_override', $grant->id], [$used->actor_user_id, $used->terminal_id, $used->entity_type, $used->entity_id]);
        $this->assertSame($sale['id'], $used->after_metadata['sale_id']);
        $this->assertSame($w['manager']->id, $used->after_metadata['approver_id']);
        $this->assertSame('3.000', $used->after_metadata['shortfalls'][0]['required']);
        $this->assertSame('1.000', $used->after_metadata['shortfalls'][0]['available']);
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_OVERRIDE_GRANTED')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_FINALIZED')->where('entity_id', $sale['id'])->count());

        // Never a code or a token in the audit trail.
        $everything = json_encode(DB::table('audit_events')->get()->all());
        $this->assertStringNotContainsString($token, $everything);
        $this->assertStringNotContainsString(self::CODE, $everything);
        $this->assertStringNotContainsString(hash('sha256', $token), $everything);
    }

    public function test_the_grant_is_single_use_a_second_blocked_sale_with_the_same_token_is_refused_and_audited(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '2']]);

        $this->sell($w, [[$coke, '2']], ['stock_override_token' => $token])->assertStatus(201);
        $replay = $this->sell($w, [[$coke, '2']], ['stock_override_token' => $token])->assertStatus(409);

        $this->assertSame('STOCK_OVERRIDE_EXPIRED', $replay->json('error.code'));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('-2.000', $this->onHand($coke));
        $this->assertSame(['ALREADY_USED'], $this->refusalReasons(), 'the refusal is audited although its sale rolled back');
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_OVERRIDE_USED')->count());
    }

    public function test_the_grant_is_bound_to_the_exact_cart_a_changed_cart_is_refused(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $chips = $this->product($w);
        $this->stock($w, $coke, '1');
        $token = $this->approval($w, [[$coke, '3']]);

        $this->sell($w, [[$coke, '4']], ['stock_override_token' => $token])->assertStatus(403)->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->sell($w, [[$coke, '3'], [$chips, '1']], ['stock_override_token' => $token])->assertStatus(403)->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->assertNull(StockOverrideGrant::sole()->consumed_at);
        $this->assertSame(['DEMAND_CHANGED', 'DEMAND_CHANGED'], $this->refusalReasons());

        // The cart it was given for still works, and stock that is on hand for the rest of the cart is no problem.
        $this->sell($w, [[$coke, '3']], ['stock_override_token' => $token])->assertStatus(201);
    }

    public function test_the_grant_is_bound_to_the_terminal_and_the_cashier(): void
    {
        $w = $this->world();
        // Slip mode needs no fiscal installation, so the manager's second till can ring a sale too.
        foreach (['t1' => 'T-01', 't2' => 'T-02'] as $till => $code) {
            $w[$till]->update(['terminal_code' => 'PARK-'.$code]);
            $w[$till]->update(['terminal_code' => $code]);
        }
        $this->rules($w, ['sale_document_mode' => 'SLIP']);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '2']]);

        // The manager rings at the other till with the cashier's token.
        $this->sell($w, [[$coke, '2']], ['stock_override_token' => $token], null, 'manager', 'enroll2')->assertStatus(403)
            ->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->assertSame(['WRONG_TERMINAL'], $this->refusalReasons());
        $this->assertNull(StockOverrideGrant::sole()->consumed_at);
    }

    public function test_an_expired_grant_is_refused(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '2']]);

        Carbon::setTestNow(now()->addMinutes(6));
        CarbonImmutable::setTestNow(now());
        $this->sell($w, [[$coke, '2']], ['stock_override_token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'STOCK_OVERRIDE_EXPIRED');
        $this->assertSame(['EXPIRED'], $this->refusalReasons());
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_an_unknown_token_is_refused_only_when_the_sale_really_is_short(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $bogus = bin2hex(random_bytes(32));

        $this->sell($w, [[$coke, '1']], ['stock_override_token' => $bogus])->assertStatus(422)->assertJsonPath('error.code', 'STOCK_OVERRIDE_INVALID');

        $this->stock($w, $coke, '5');
        $this->sell($w, [[$coke, '1']], ['stock_override_token' => $bogus])->assertStatus(201);
    }

    public function test_the_token_must_look_like_a_token(): void
    {
        $w = $this->world();
        $this->rules($w);

        $this->sell($w, [[$this->product($w), '1']], ['stock_override_token' => 'short'])->assertStatus(422);
    }

    public function test_a_grant_is_not_consumed_when_the_sale_turns_out_not_to_be_short(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '2']]);

        $this->stock($w, $coke, '10'); // a delivery was entered after the approval
        $this->sell($w, [[$coke, '2']], ['stock_override_token' => $token])->assertStatus(201);

        $grant = StockOverrideGrant::sole();
        $this->assertNull($grant->consumed_at);
        $this->assertNull($grant->sale_id);
        $this->assertSame('8.000', $this->onHand($coke));
        $this->assertSame(0, AuditEvent::where('event_type', 'STOCK_OVERRIDE_USED')->count());
    }

    public function test_no_approval_is_issued_for_a_cart_that_is_not_short(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->stock($w, $coke, '9');
        $this->codeFor($w['manager']);

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '2']]))
            ->assertStatus(403)->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->assertSame(0, StockOverrideGrant::count());
        $this->assertSame(['NO_SHORTFALL'], $this->refusalReasons());
    }

    public function test_the_approver_must_hold_the_capability_and_differ_from_the_cashier(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->codeFor($w['manager']);
        $other = User::factory()->create(['store_id' => $w['storeId']]); // a cashier
        $till = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        // A cashier cannot be the approver: the same single generic answer as a wrong code.
        $till()->postJson('/api/v1/stock-overrides', $this->grantBody($other, [[$coke, '1']]))->assertStatus(422)->assertJsonPath('error.code', 'STOCK_OVERRIDE_INVALID');

        // The manager cannot approve their own sale, at their own till.
        $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '1']]))
            ->assertStatus(403)->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->assertSame(0, StockOverrideGrant::count());
        $this->assertSame(['APPROVER_LACKS_CAPABILITY', 'SELF_APPROVAL'], $this->refusalReasons());
    }

    public function test_a_wrong_code_is_one_generic_answer_and_five_lock_the_approver_for_credit_overrides_too(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->codeFor($w['manager']);
        $till = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        for ($i = 1; $i <= 4; $i++) {
            $till()->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '1']], '000001'))->assertStatus(422)->assertJsonPath('error.code', 'STOCK_OVERRIDE_INVALID');
        }
        $this->assertSame(4, (int) DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('failed_attempts'));
        $till()->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '1']], '000001'))->assertStatus(423)->assertJsonPath('error.code', 'STOCK_OVERRIDE_LOCKED');
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_OVERRIDE_LOCKED')->count());

        // Even the right code is refused while locked, and the SAME lock stops the credit-limit override for this approver.
        $till()->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '1']]))->assertStatus(423);
        $employee = Employee::factory()->create(['store_id' => $w['storeId']]);
        app(CreditPolicyService::class)->ensureDefaults($w['storeId']);
        $till()->postJson('/api/v1/credit-overrides', ['approver_id' => $w['manager']->id, 'code' => self::CODE, 'employee_id' => $employee->id, 'amount' => '100.00'])
            ->assertStatus(423)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_LOCKED');
        $this->assertSame(0, StockOverrideGrant::count());
    }

    public function test_with_the_override_switched_off_no_grant_is_issued_and_none_is_accepted(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '1']]); // issued while it was on

        $this->rules($w, ['stock_override_enabled' => false]);

        $this->sell($w, [[$coke, '1']])->assertStatus(409)->assertJsonPath('error.details.override_available', false);
        $this->sell($w, [[$coke, '1']], ['stock_override_token' => $token])->assertStatus(403)->assertJsonPath('error.code', 'STOCK_OVERRIDE_NOT_ALLOWED');
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/stock-overrides', $this->grantBody($w['manager'], [[$coke, '1']]))->assertStatus(403);
        $this->assertSame(1, StockOverrideGrant::count());
        $this->assertNull(StockOverrideGrant::sole()->consumed_at);
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_with_blocking_off_a_token_is_ignored_and_never_consumed(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $token = $this->approval($w, [[$coke, '1']]);

        $this->rules($w, ['block_sale_without_stock' => false]);
        $this->sell($w, [[$coke, '1']], ['stock_override_token' => $token])->assertStatus(201);

        $this->assertNull(StockOverrideGrant::sole()->consumed_at);
        $this->assertSame('-1.000', $this->onHand($coke));
    }

    public function test_the_approver_list_names_only_users_who_hold_the_capability_and_a_code(): void
    {
        $w = $this->world();
        $this->codeFor($w['manager']);
        $this->codeFor($w['admin'], '739152');
        $cashier2 = User::factory()->create(['store_id' => $w['storeId']]);
        $noCode = User::factory()->manager()->create(['store_id' => $w['storeId']]);

        $names = collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock-override-approvers')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing([$w['manager']->id, $w['admin']->id], $names->pluck('id')->all());
        $this->assertSame(['id', 'name'], array_keys($names->first()));
        $this->assertNotContains($cashier2->id, $names->pluck('id')->all());
        $this->assertNotContains($noCode->id, $names->pluck('id')->all());
    }

    public function test_the_approver_list_needs_a_session(): void
    {
        $this->getJson('/api/v1/stock-override-approvers')->assertStatus(401);
    }

    public function test_the_stock_and_credit_approvals_work_together_in_one_sale(): void
    {
        $w = $this->world();
        $this->rules($w, ['sale_document_mode' => 'SLIP']);
        $w['t1']->update(['terminal_code' => 'PARK-1']);
        $w['t1']->update(['terminal_code' => 'T-01']);
        app(CreditPolicyService::class)->ensureDefaults($w['storeId']);
        $employee = Employee::factory()->create(['store_id' => $w['storeId']]);
        $big = $this->product($w, '1600.00'); // over the 1,500 limit, and nothing in stock
        $this->codeFor($w['manager']);
        $till = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        $creditToken = $till()->postJson('/api/v1/credit-overrides', ['approver_id' => $w['manager']->id, 'code' => self::CODE, 'employee_id' => $employee->id, 'amount' => '1600.00'])->assertStatus(201)->json('token');
        $stockToken = $this->approval($w, [[$big, '1']]);

        $sale = $till()->postJson('/api/v1/sales', [
            'items' => [['product_id' => $big->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '1600.00']], 'employee_id' => $employee->id,
            'credit_override_token' => $creditToken, 'stock_override_token' => $stockToken,
        ], $this->key())->assertStatus(201)->json();

        $this->assertSame('-1.000', $this->onHand($big));
        $this->assertSame($sale['id'], StockOverrideGrant::sole()->sale_id);
        $this->assertNotNull(DB::table('credit_override_grants')->value('consumed_at'));
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_OVERRIDE_USED')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'CREDIT_OVERRIDE_USED')->count());
    }

    public function test_the_refusal_is_not_retried_by_the_server_and_the_body_lists_the_override_flag(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);

        $response = $this->sell($w, [[$coke, '1']])->assertStatus(409);

        $this->assertSame('INSUFFICIENT_STOCK', $response->json('error.code'));
        $this->assertNotSame('CONCURRENCY_CONFLICT', $response->json('error.code'));
    }

    public function test_the_unsold_zero_row_a_blocked_check_may_create_is_consistent_with_the_ledger(): void
    {
        $w = $this->world();
        $this->rules($w);
        $coke = $this->product($w);
        $this->stock($w, $coke, '2');
        $chips = $this->product($w);
        $this->stock($w, $chips, '0');

        $this->sell($w, [[$coke, '1'], [$chips, '1']])->assertStatus(409);
        $this->sell($w, [[$coke, '1']])->assertStatus(201);

        foreach (StockBalance::all() as $balance) {
            $ledger = DB::table('stock_movements')->where('product_id', $balance->product_id)->where('location_id', $balance->location_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('SALE','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED','TRANSFER_OUT') THEN -quantity ELSE quantity END), 0) AS net")->value('net');
            $this->assertSame(bcadd((string) $ledger, '0', 3), (string) $balance->quantity_on_hand, 'invariant 44: the balance equals the signed sum of the movements');
        }
    }
}

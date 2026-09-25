<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\RefundSettlement;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml saleRefund / refundGet / refundList / refundApprove / refundReject (domain-model.md
 * SS2.9, state-machines.md SS3). As with voids, sales are rung through real checkout and execution
 * happens at a different terminal from the sale.
 */
class SaleRefundHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function balance(string $productId): ?string
    {
        return StockBalance::where('product_id', $productId)->first()?->quantity_on_hand;
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $lines  [sale_item_id, quantity, disposition]
     * @param  list<array{0: string, 1: string}>  $settlements  [payment_method, amount]
     * @return array<string, mixed>
     */
    private function body(array $lines, array $settlements, string $reason = 'Customer returned it'): array
    {
        return [
            'items' => array_map(fn (array $line) => ['sale_item_id' => $line[0], 'quantity' => $line[1], 'disposition' => $line[2] ?? 'RETURN_TO_STOCK'], $lines),
            'settlements' => array_map(fn (array $s) => ['payment_method' => $s[0], 'amount' => $s[1]], $settlements),
            'reason' => $reason,
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function requestAsCashier(array $w, string $saleId, array $body): array
    {
        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$saleId}/refunds", $body, $this->key());
        $response->assertStatus(201);

        return $response->json();
    }

    private function closeFiscalDay(string $fiscalDayId): void
    {
        DB::table('fiscal_days')->where('id', $fiscalDayId)->update(['status' => 'CLOSED', 'closed_at' => now()]);
    }

    // -------------------------------------------------------------- execution

    public function test_a_manager_refunds_part_of_a_sale_immediately_in_their_own_context(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$item, '1']], [['CASH', '100.00']]), $this->key());

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'COMPLETED',
            'sale_id' => $sale['id'],
            'refund_total' => '100.00',
            'requested_by' => $w['manager']->id,
            'approved_by' => $w['manager']->id,
            'terminal_id' => $w['t2']->id,
            'shift_id' => $w['managerShift']->id,
            'fiscal_day_id' => $w['managerShift']->fiscal_day_id,
            'sale' => ['status' => 'PARTIALLY_REFUNDED'],
        ]);
        $this->assertNotNull($response->json('refunded_at'));
        $this->assertSame('1.000', $response->json('items.0.quantity_returned'));
        $this->assertSame('100.00', $response->json('items.0.unit_refund_amount'));
        $this->assertNotNull($response->json('items.0.id'));
        $this->assertSame([['sale_item_id' => $item, 'remaining_quantity' => '1.000', 'remaining_amount' => '100.00']], $response->json('remaining_refundable'));

        // -2 sold, +1 restocked at the original location, against the refund line.
        $this->assertSame('-1.000', $this->balance($w['product']->id));
        $movement = StockMovement::where('movement_type', 'SALE_RETURN')->firstOrFail();
        $this->assertSame('refund_item', $movement->reference_type);
        $this->assertSame($w['location']->id, $movement->location_id);
        $this->assertSame($w['t2']->id, $movement->terminal_id);

        $this->assertSame(['REFUND_REQUESTED', 'REFUND_CREATED'], AuditEvent::whereIn('event_type', ['REFUND_REQUESTED', 'REFUND_CREATED'])->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'REFUND')->where('source_id', $response->json('id'))->count());
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'grand_total' => '200.00']);

        $detail = $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$sale['id']}");
        $this->assertSame([$response->json('id')], collect($detail->json('refunds'))->pluck('id')->all());
    }

    public function test_partial_refunds_of_a_discounted_line_add_up_to_exactly_the_line_and_complete_the_sale(): void
    {
        $w = $this->world();
        // A line discount needs DISCOUNT_OVERRIDE, which a plain CASHIER does not hold; T2 (the manager's
        // own terminal) has no fiscal installation in this scenario, so the cashier's own T1 is kept and
        // just promoted for this one call -- nothing below depends on the cashier's role.
        $w['cashier']->update(['role' => 'MANAGER']);
        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product2']->id, 'quantity' => '3', 'line_discount_amount' => '0.01']],
            'payments' => [['method' => 'CASH', 'amount' => '149.99']],
        ], $this->key());
        $response->assertStatus(201);
        $sale = $response->json();
        $item = $sale['items'][0];
        $this->assertSame('149.99', $item['net_line_amount']);

        // 149.99 / 3 = 49.9966..: 50.00, then 49.99, then the remainder 50.00 -- never three equal shares.
        $amounts = [];
        foreach (['50.00', '49.99', '50.00'] as $expected) {
            $refund = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$item['id'], '1']], [['CASH', $expected]]), $this->key());
            $refund->assertStatus(201);
            $amounts[] = $refund->json('items.0.unit_refund_amount');
        }

        $this->assertSame(['50.00', '49.99', '50.00'], $amounts);
        $this->assertSame('149.99', number_format((float) RefundItem::where('sale_item_id', $item['id'])->sum('unit_refund_amount'), 2, '.', ''));
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'REFUNDED']);

        // Nothing left to give back.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$item['id'], '1']], [['CASH', '50.00']]), $this->key())
            ->assertStatus(422)->assertJson(['error' => ['code' => 'REFUND_EXCEEDS_REMAINING_QUANTITY']]);
    }

    public function test_only_return_to_stock_lines_go_back_on_the_shelf(): void
    {
        $w = $this->world();
        $sale = $this->ring($w, [[$w['product'], '3'], [$w['product2'], '1']]);
        [$one, $two] = [$sale['items'][0]['id'], $sale['items'][1]['id']];

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([
            [$one, '1', 'DAMAGED'], [$two, '1', 'RETURN_TO_STOCK'],
        ], [['CASH', '150.00']]), $this->key())->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([
            [$one, '1', 'EXPIRED'], [$one, '1', 'DISPOSED'],
        ], [['CASH', '200.00']]), $this->key())->assertStatus(422);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$one, '1', 'DISPOSED']], [['CASH', '100.00']]), $this->key())->assertStatus(201);

        // Product 1: sold 3, none restocked (DAMAGED, DISPOSED). Product 2: sold 1, restocked 1.
        $this->assertSame('-3.000', $this->balance($w['product']->id));
        $this->assertSame('0.000', $this->balance($w['product2']->id));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
    }

    public function test_a_sale_from_a_closed_fiscal_day_can_still_be_refunded_in_todays_context(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->closeFiscalDay($sale['fiscal_day_id']);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$sale['items'][0]['id'], '2']], [['CASH', '200.00']]), $this->key());

        $response->assertStatus(201);
        $this->assertSame($w['managerShift']->fiscal_day_id, $response->json('fiscal_day_id'));
        $this->assertNotSame($sale['fiscal_day_id'], $response->json('fiscal_day_id'));
        $response->assertJson(['sale' => ['status' => 'REFUNDED']]);
    }

    public function test_the_money_can_go_back_by_more_than_one_method(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [
                ['payment_method' => 'CASH', 'amount' => '60.00'],
                ['payment_method' => 'GCASH', 'amount' => '40.00', 'external_reference' => 'GC-99123'],
            ],
            'reason' => 'Split back',
        ], $this->key());

        $response->assertStatus(201);
        $this->assertEqualsCanonicalizing(['CASH', 'GCASH'], collect($response->json('settlements'))->pluck('payment_method')->all());
        $this->assertSame('GC-99123', RefundSettlement::where('payment_method', 'GCASH')->value('external_reference'));
        $this->assertSame('100.00', number_format((float) RefundSettlement::sum('amount'), 2, '.', ''));
    }

    // ------------------------------------------------------- request, then approve

    public function test_a_cashier_only_requests_and_nothing_is_recorded_as_executed(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];

        $refund = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '1']], [['CASH', '100.00']]));

        $this->assertSame('REQUESTED', $refund['status']);
        $this->assertSame('100.00', $refund['refund_total']);
        $this->assertNull($refund['approved_by']);
        $this->assertNull($refund['resolved_at']);
        $this->assertNull($refund['refunded_at']);
        $this->assertNull($refund['terminal_id']);
        $this->assertNull($refund['shift_id']);
        $this->assertSame('COMPLETED', $refund['sale']['status']);

        // What was asked for is visible to the approver, but no executed rows exist yet (invariant #72).
        $this->assertNull($refund['items'][0]['id']);
        $this->assertSame($item, $refund['items'][0]['sale_item_id']);
        $this->assertSame('100.00', $refund['items'][0]['unit_refund_amount']);
        $this->assertNull($refund['settlements'][0]['id']);
        $this->assertSame('CASH', $refund['settlements'][0]['payment_method']);
        $this->assertSame(0, RefundItem::count());
        $this->assertSame(0, RefundSettlement::count());
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame('-2.000', $this->balance($w['product']->id));
        $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'REFUND')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'REFUND_REQUESTED')->count());
    }

    public function test_approval_completes_the_recorded_request_in_the_approvers_context(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $refund = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '1']], [['CASH', '60.00'], ['GCASH', '40.00']]));

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->key());

        $response->assertOk();
        $response->assertJson([
            'id' => $refund['id'],
            'status' => 'COMPLETED',
            'requested_by' => $w['cashier']->id,
            'approved_by' => $w['manager']->id,
            'terminal_id' => $w['t2']->id,
            'shift_id' => $w['managerShift']->id,
            'refund_total' => '100.00',
            'sale' => ['status' => 'PARTIALLY_REFUNDED'],
        ]);
        $this->assertNotNull($response->json('items.0.id'));
        $this->assertCount(2, $response->json('settlements'));
        $this->assertSame('-1.000', $this->balance($w['product']->id));
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'REFUND')->where('source_id', $refund['id'])->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'REFUND_CREATED')->where('entity_id', $refund['id'])->count());
    }

    public function test_approval_re_validates_against_refunds_completed_since_the_request(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $first = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '2']], [['CASH', '200.00']]));
        $second = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '2']], [['CASH', '200.00']]));

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$first['id']}/approve", [], $this->key())->assertOk();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$second['id']}/approve", [], $this->key())
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'REFUND_EXCEEDS_REMAINING_QUANTITY']]);

        // Left exactly as it was, for a human to decide.
        $this->assertSame('REQUESTED', Refund::find($second['id'])->status);
        $this->assertSame(1, RefundItem::count());
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$second['id']}/reject", ['reason' => 'Already refunded'], $this->key())->assertOk()->assertJson(['status' => 'REJECTED']);
    }

    public function test_an_approver_without_an_open_shift_leaves_the_refund_requested_and_the_key_reusable(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $refund = $this->requestAsCashier($w, $sale['id'], $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]));
        $headers = $this->key();

        $this->asUser($w['admin'], $w['enroll2'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $headers)
            ->assertStatus(409)->assertJson(['error' => ['code' => 'SHIFT_NOT_OPEN']]);
        $this->assertSame('REQUESTED', Refund::find($refund['id'])->status);
        $this->assertSame(0, RefundItem::count());

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $headers)->assertOk();
    }

    public function test_an_executing_terminal_with_no_open_fiscal_day_is_fiscal_day_closed(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->closeFiscalDay($w['managerShift']->fiscal_day_id);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]), $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'FISCAL_DAY_CLOSED']]);
        $this->assertSame(0, Refund::count());
        $this->assertSame(0, AuditEvent::where('event_type', 'REFUND_REQUESTED')->count());
    }

    public function test_a_voided_sale_cannot_be_refunded_even_with_a_pending_request(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $pending = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '1']], [['CASH', '100.00']]));
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Whole sale wrong'], $this->key())->assertStatus(201);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$pending['id']}/approve", [], $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'REFUND_NOT_ALLOWED']]);
        $this->assertSame('REQUESTED', Refund::find($pending['id'])->status);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$item, '1']], [['CASH', '100.00']]), $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'REFUND_NOT_ALLOWED']]);
    }

    // ---------------------------------------------------------- caps and shape

    public function test_more_than_remains_or_settlements_that_do_not_add_up_are_refused_and_change_nothing(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $post = fn (array $body) => $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $this->key());

        $post($this->body([[$item, '3']], [['CASH', '300.00']]))->assertStatus(422)->assertJson(['error' => ['code' => 'REFUND_EXCEEDS_REMAINING_QUANTITY']]);
        $post($this->body([[$item, '1']], [['CASH', '99.99']]))->assertStatus(422)->assertJson(['error' => ['code' => 'REFUND_SETTLEMENT_MISMATCH']]);
        $post($this->body([[$item, '1']], [['CASH', '60.00'], ['GCASH', '50.00']]))->assertStatus(422)->assertJson(['error' => ['code' => 'REFUND_SETTLEMENT_MISMATCH']]);

        $this->assertSame(0, Refund::count());
        $this->assertSame('-2.000', $this->balance($w['product']->id));
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'COMPLETED']);
    }

    public function test_a_line_from_another_sale_is_a_field_error(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $other = $this->ring($w);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$other['items'][0]['id'], '1']], [['CASH', '100.00']]), $this->key());

        $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('items.0.sale_item_id', $response->json('error.details'));
        $this->assertSame(0, Refund::count());
    }

    public function test_the_request_shape_is_validated(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $valid = $this->body([[$item, '1']], [['CASH', '100.00']]);

        foreach ([
            'items.0.quantity' => ['items' => [['sale_item_id' => $item, 'quantity' => '0', 'disposition' => 'DAMAGED']]],
            'items.0.quantity ' => ['items' => [['sale_item_id' => $item, 'quantity' => '1.2345', 'disposition' => 'DAMAGED']]],
            'items.0.disposition' => ['items' => [['sale_item_id' => $item, 'quantity' => '1']]],
            'items.0.disposition ' => ['items' => [['sale_item_id' => $item, 'quantity' => '1', 'disposition' => 'GIFTED']]],
            'items.0.sale_item_id' => ['items' => [['sale_item_id' => 'nope', 'quantity' => '1', 'disposition' => 'DAMAGED']]],
            'items.1.sale_item_id' => ['items' => [['sale_item_id' => $item, 'quantity' => '1', 'disposition' => 'DAMAGED'], ['sale_item_id' => $item, 'quantity' => '1', 'disposition' => 'DAMAGED']]],
            'items' => ['items' => []],
            'settlements' => ['settlements' => []],
            'settlements.0.amount' => ['settlements' => [['payment_method' => 'CASH', 'amount' => '0.00']]],
            'settlements.0.amount ' => ['settlements' => [['payment_method' => 'CASH', 'amount' => '100']]],
            'settlements.0.payment_method' => ['settlements' => [['payment_method' => 'BARTER', 'amount' => '100.00']]],
            'reason' => ['reason' => '   '],
        ] as $field => $override) {
            $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $override + $valid, $this->key());

            $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
            $this->assertArrayHasKey(trim($field), $response->json('error.details'), $field);
        }
        $this->assertSame(0, Refund::count());
    }

    public function test_the_idempotency_key_is_required_and_a_retry_replays_once(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $body = $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body)
            ->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);

        $headers = $this->key();
        $first = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $headers);
        $second = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $headers);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Refund::count());
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());

        $changed = $this->body([[$sale['items'][0]['id'], '2']], [['CASH', '200.00']]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $changed, $headers)
            ->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
    }

    public function test_an_unknown_or_foreign_sale_is_not_found(): void
    {
        $w = $this->world();
        $foreignWorld = $this->world();
        $foreign = $this->ring($foreignWorld);
        $body = $this->body([[$foreign['items'][0]['id'], '1']], [['CASH', '100.00']]);

        foreach ([$foreign['id'], (string) Str::uuid()] as $id) {
            $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$id}/refunds", $body, $this->key())
                ->assertStatus(404)->assertJson(['error' => ['code' => 'SALE_NOT_FOUND']]);
        }
    }

    // ---------------------------------------------------------------- access

    public function test_an_unauthenticated_request_cannot_touch_refunds(): void
    {
        $id = (string) Str::uuid();

        $this->postJson("/api/v1/sales/{$id}/refunds", [], $this->key())->assertStatus(401);
        $this->postJson("/api/v1/refunds/{$id}/approve", [], $this->key())->assertStatus(401);
        $this->postJson("/api/v1/refunds/{$id}/reject", ['reason' => 'x'], $this->key())->assertStatus(401);
        $this->getJson('/api/v1/refunds')->assertStatus(401);
    }

    public function test_access_rules(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $body = $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]);
        $refund = $this->requestAsCashier($w, $sale['id'], $body);

        $this->asUser($w['manager'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($w['cashier'])->postJson("/api/v1/refunds/{$refund['id']}/reject", ['reason' => 'x'], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);

        $this->assertSame('REQUESTED', Refund::find($refund['id'])->status);
    }

    // ---------------------------------------------------------------- reject

    public function test_a_rejection_is_a_decision_only_and_needs_no_terminal(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $refund = $this->requestAsCashier($w, $sale['id'], $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]));

        $response = $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$refund['id']}/reject", ['reason' => 'Outside the return window'], $this->key());

        $response->assertOk();
        $response->assertJson(['id' => $refund['id'], 'status' => 'REJECTED', 'approved_by' => $w['manager']->id, 'sale' => ['status' => 'COMPLETED']]);
        $this->assertNotNull($response->json('resolved_at'));
        $this->assertNull($response->json('refunded_at'));
        $this->assertNull($response->json('terminal_id'));
        $this->assertSame(0, RefundItem::count());
        $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'REFUND')->count());
        $this->assertSame('Outside the return window', AuditEvent::where('event_type', 'REFUND_REJECTED')->firstOrFail()->reason);
        $this->assertSame('-2.000', $this->balance($w['product']->id));
    }

    public function test_repeating_a_rejection_replays_it_and_the_rest_is_not_pending(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $refund = $this->requestAsCashier($w, $sale['id'], $this->body([[$sale['items'][0]['id'], '1']], [['CASH', '100.00']]));
        $reject = fn (string $reason) => $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$refund['id']}/reject", ['reason' => $reason], $this->key());

        $reject('No')->assertOk();
        $reject('No')->assertOk()->assertJson(['status' => 'REJECTED']);
        $this->assertSame(1, AuditEvent::where('event_type', 'REFUND_REJECTED')->count());
        $reject('Other')->assertStatus(409)->assertJson(['error' => ['code' => 'REFUND_NOT_PENDING_APPROVAL']]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'REFUND_NOT_PENDING_APPROVAL']]);

        $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$refund['id']}/reject", [], $this->key())->assertStatus(422);
        $this->asUser($w['manager'])->postJson("/api/v1/refunds/{$refund['id']}/reject", ['reason' => 'No'])->assertStatus(400);
    }

    // ----------------------------------------------------------------- reads

    public function test_refund_reads_are_scoped_filterable_and_need_only_a_session(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $pending = $this->requestAsCashier($w, $sale['id'], $this->body([[$item, '1']], [['CASH', '100.00']]));
        $done = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $this->body([[$item, '1']], [['CASH', '100.00']]), $this->key())->json();
        $other = $this->world();
        $otherSale = $this->ring($other);
        $this->asUser($other['manager'], $other['enroll2'])->postJson("/api/v1/sales/{$otherSale['id']}/refunds", $this->body([[$otherSale['items'][0]['id'], '1']], [['CASH', '100.00']]), $this->key())->assertStatus(201);

        $list = fn (string $query = '') => $this->asUser($w['cashier'])->getJson('/api/v1/refunds'.$query);

        $this->assertEqualsCanonicalizing([$pending['id'], $done['id']], collect($list()->json('data'))->pluck('id')->all());
        $this->assertSame([$pending['id']], collect($list('?status=REQUESTED')->json('data'))->pluck('id')->all());
        $this->assertSame([$done['id']], collect($list('?status=COMPLETED')->json('data'))->pluck('id')->all());
        $this->assertCount(2, $list("?sale_id={$sale['id']}")->json('data'));
        $this->assertSame([], $list('?status=NOPE')->json('data'));
        $this->assertSame([], $list('?sale_id=not-a-uuid')->json('data'));

        $get = $this->asUser($w['cashier'])->getJson("/api/v1/refunds/{$pending['id']}");
        $get->assertOk()->assertJson(['id' => $pending['id'], 'status' => 'REQUESTED', 'sale' => ['id' => $sale['id']]]);
        $this->assertNull($get->json('items.0.id'));
        $this->assertSame('COMPLETED', $this->asUser($w['cashier'])->getJson("/api/v1/refunds/{$done['id']}")->json('status'));

        $this->asUser($w['cashier'])->getJson('/api/v1/refunds/'.(string) Str::uuid())->assertStatus(404)->assertJson(['error' => ['code' => 'REFUND_NOT_FOUND']]);
        $foreign = Refund::whereNotIn('id', [$pending['id'], $done['id']])->firstOrFail();
        $this->asUser($w['cashier'])->getJson("/api/v1/refunds/{$foreign->id}")->assertStatus(404)->assertJson(['error' => ['code' => 'REFUND_NOT_FOUND']]);
    }
}

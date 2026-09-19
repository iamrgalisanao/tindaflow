<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\SaleVoid;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml saleVoid / voidGet / voidList / voidApprove / voidReject (domain-model.md SS2.8,
 * state-machines.md SS2). Sales are rung through the real checkout so the reversal is proven against
 * the real SALE movements, and the executing user is always at a different terminal from the sale so
 * that "processing context is the executor's, not the sale's" (invariants #67-#69) is really tested.
 */
class SaleVoidHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function balance(string $productId): ?string
    {
        return StockBalance::where('product_id', $productId)->first()?->quantity_on_hand;
    }

    /** @param  array<string, mixed>  $w */
    private function requestAsCashier(array $w, string $saleId, string $reason = 'Customer changed their mind'): array
    {
        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$saleId}/void", ['reason' => $reason], $this->key());
        $response->assertStatus(201);

        return $response->json();
    }

    private function closeFiscalDay(string $fiscalDayId): void
    {
        DB::table('fiscal_days')->where('id', $fiscalDayId)->update(['status' => 'CLOSED', 'closed_at' => now()]);
    }

    // -------------------------------------------------------------- execution

    public function test_a_manager_at_another_terminal_voids_a_sale_immediately_in_their_own_context(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->assertSame('-2.000', $this->balance($w['product']->id));

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key());

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'VOIDED',
            'reason' => 'Wrong item',
            'requested_by' => $w['manager']->id,
            'approved_by' => $w['manager']->id,
            'terminal_id' => $w['t2']->id,
            'shift_id' => $w['managerShift']->id,
            'fiscal_day_id' => $w['managerShift']->fiscal_day_id,
            'sale' => ['id' => $sale['id'], 'status' => 'VOIDED', 'terminal_id' => $w['t1']->id],
        ]);
        $this->assertNotNull($response->json('resolved_at'));

        // Full reversal: one compensating SALE_RETURN per line at the original location; nothing original edited.
        $this->assertSame('0.000', $this->balance($w['product']->id));
        $returns = StockMovement::where('movement_type', 'SALE_RETURN')->get();
        $this->assertCount(1, $returns);
        $this->assertSame('2.000', $returns[0]->quantity);
        $this->assertSame($w['location']->id, $returns[0]->location_id);
        $this->assertSame($w['t2']->id, $returns[0]->terminal_id);
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->count());

        $this->assertSame(['SALE_FINALIZED', 'SALE_VOID_REQUESTED', 'SALE_VOIDED'], AuditEvent::orderBy('occurred_at')->orderBy('id')->pluck('event_type')->intersect(['SALE_FINALIZED', 'SALE_VOID_REQUESTED', 'SALE_VOIDED'])->values()->all());
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'VOID')->where('source_id', $response->json('id'))->count());
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'grand_total' => '200.00', 'status' => 'VOIDED']);
    }

    public function test_a_cashier_only_requests_and_nothing_is_reversed(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);

        $void = $this->requestAsCashier($w, $sale['id']);

        $this->assertSame('REQUESTED', $void['status']);
        $this->assertNull($void['approved_by']);
        $this->assertNull($void['resolved_at']);
        $this->assertNull($void['terminal_id']);
        $this->assertNull($void['fiscal_day_id']);
        $this->assertNull($void['shift_id']);
        $this->assertSame('COMPLETED', $void['sale']['status']);
        $this->assertSame('-2.000', $this->balance($w['product']->id));
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'VOID')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_VOID_REQUESTED')->where('actor_user_id', $w['cashier']->id)->count());

        $detail = $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$sale['id']}");
        $this->assertSame($void['id'], $detail->json('void.id'));
        $this->assertSame('REQUESTED', $detail->json('void.status'));
    }

    public function test_approval_executes_in_the_approvers_context_not_the_requesters(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $void = $this->requestAsCashier($w, $sale['id']);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key());

        $response->assertOk();
        $response->assertJson([
            'id' => $void['id'],
            'status' => 'VOIDED',
            'requested_by' => $w['cashier']->id,
            'approved_by' => $w['manager']->id,
            'terminal_id' => $w['t2']->id,
            'shift_id' => $w['managerShift']->id,
            'sale' => ['status' => 'VOIDED'],
        ]);
        $this->assertNotSame($w['t1']->id, $response->json('terminal_id'));
        $this->assertSame('0.000', $this->balance($w['product']->id));
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'VOID')->where('source_id', $void['id'])->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_VOIDED')->where('entity_id', $void['id'])->count());
    }

    public function test_a_retried_approval_returns_the_original_result_and_reverses_once(): void
    {
        $w = $this->world();
        $void = $this->requestAsCashier($w, $this->ring($w)['id']);
        $headers = $this->key();

        $first = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $headers);
        $second = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('resolved_at'), $second->json('resolved_at'));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'VOID')->count());
    }

    public function test_the_same_key_for_a_different_void_is_a_conflict(): void
    {
        $w = $this->world();
        $one = $this->requestAsCashier($w, $this->ring($w)['id']);
        $two = $this->requestAsCashier($w, $this->ring($w)['id']);
        $headers = $this->key();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$one['id']}/approve", [], $headers)->assertOk();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$two['id']}/approve", [], $headers)
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
    }

    // ---------------------------------------------- failed execution changes nothing

    public function test_an_approver_without_an_open_shift_leaves_the_void_requested_and_the_key_reusable(): void
    {
        $w = $this->world();
        $void = $this->requestAsCashier($w, $this->ring($w)['id']);
        $headers = $this->key();

        // The admin is at T2 but has no shift there (the manager's is the one open).
        $this->asUser($w['admin'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $headers)
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'SHIFT_NOT_OPEN']]);

        $this->assertSame('REQUESTED', SaleVoid::find($void['id'])->status);
        $this->assertNull(SaleVoid::find($void['id'])->terminal_id);
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'SALE_VOIDED')->count());
        $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'VOID')->count());

        // The blocking condition is corrected (someone with a shift approves): the same key works.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $headers)->assertOk();
    }

    public function test_an_executing_terminal_with_no_open_fiscal_day_is_fiscal_day_closed_and_changes_nothing(): void
    {
        $w = $this->world();
        $void = $this->requestAsCashier($w, $this->ring($w)['id']);
        $this->closeFiscalDay($w['managerShift']->fiscal_day_id);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'FISCAL_DAY_CLOSED']]);

        $this->assertSame('REQUESTED', SaleVoid::find($void['id'])->status);
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->count());
    }

    public function test_a_sale_whose_fiscal_day_has_closed_can_no_longer_be_voided(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $void = $this->requestAsCashier($w, $sale['id']);
        $this->closeFiscalDay($sale['fiscal_day_id']);

        // At approval: stays REQUESTED, never auto-rejected (state-machines.md SS2).
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'SALE_NOT_VOIDABLE']]);
        $this->assertSame('REQUESTED', SaleVoid::find($void['id'])->status);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'COMPLETED']);

        // And a fresh request is refused up front, by the cashier and by the manager alike.
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'SALE_NOT_VOIDABLE']]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'SALE_NOT_VOIDABLE']]);
    }

    public function test_a_sale_with_a_completed_refund_cannot_be_voided(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'One was damaged',
        ], $this->key())->assertStatus(201);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'SALE_NOT_VOIDABLE']]);
    }

    public function test_a_sale_can_only_be_voided_once(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'first'], $this->key())->assertStatus(201);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'second'], $this->key())
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'SALE_ALREADY_VOIDED']]);
        $this->assertSame(1, SaleVoid::where('sale_id', $sale['id'])->where('status', 'VOIDED')->count());
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
    }

    // ----------------------------------------------------------------- input

    public function test_a_reason_is_required(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);

        foreach ([[], ['reason' => ''], ['reason' => '   ']] as $body) {
            $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", $body, $this->key())
                ->assertStatus(422)
                ->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->assertSame(0, SaleVoid::count());
    }

    public function test_the_idempotency_key_is_required_and_a_retry_replays_once(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $body = ['reason' => 'Wrong item'];

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", $body)
            ->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);

        $headers = $this->key();
        $first = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", $body, $headers);
        $second = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", $body, $headers);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, SaleVoid::count());
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Different'], $headers)
            ->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
    }

    public function test_an_unknown_or_foreign_sale_is_not_found(): void
    {
        $w = $this->world();
        $foreign = $this->ring($this->world());

        foreach ([$foreign['id'], (string) Str::uuid()] as $id) {
            $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$id}/void", ['reason' => 'x'], $this->key())
                ->assertStatus(404)
                ->assertJson(['error' => ['code' => 'SALE_NOT_FOUND']]);
        }
        $this->assertDatabaseHas('sales', ['id' => $foreign['id'], 'status' => 'COMPLETED']);
    }

    // ---------------------------------------------------------------- access

    public function test_an_unauthenticated_request_cannot_touch_voids(): void
    {
        $id = (string) Str::uuid();

        $this->postJson("/api/v1/sales/{$id}/void", ['reason' => 'x'], $this->key())->assertStatus(401);
        $this->postJson("/api/v1/voids/{$id}/approve", [], $this->key())->assertStatus(401);
        $this->postJson("/api/v1/voids/{$id}/reject", ['reason' => 'x'], $this->key())->assertStatus(401);
        $this->getJson('/api/v1/voids')->assertStatus(401);
    }

    public function test_access_rules(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $void = $this->requestAsCashier($w, $sale['id']);

        // A holder of SALE_VOID in a browser that is not an enrolled terminal.
        $this->asUser($w['manager'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);

        // A cashier requests but can neither approve nor reject.
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($w['cashier'])->postJson("/api/v1/voids/{$void['id']}/reject", ['reason' => 'x'], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);

        // Approval is execution: it needs an enrolled terminal.
        $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);

        $this->assertSame('REQUESTED', SaleVoid::find($void['id'])->status);
    }

    // ---------------------------------------------------------------- reject

    public function test_a_rejection_is_a_decision_only_and_needs_no_terminal(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $void = $this->requestAsCashier($w, $sale['id']);

        $response = $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/reject", ['reason' => 'Customer already left with it'], $this->key());

        $response->assertOk();
        $response->assertJson(['id' => $void['id'], 'status' => 'REJECTED', 'approved_by' => $w['manager']->id, 'sale' => ['status' => 'COMPLETED']]);
        $this->assertNotNull($response->json('resolved_at'));
        $this->assertNull($response->json('terminal_id'));
        $this->assertSame('-2.000', $this->balance($w['product']->id));
        $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'VOID')->count());
        $audit = AuditEvent::where('event_type', 'SALE_VOID_REJECTED')->firstOrFail();
        $this->assertSame('Customer already left with it', $audit->reason);
        $this->assertSame($void['id'], $audit->entity_id);
    }

    public function test_repeating_a_rejection_replays_it_and_a_different_one_conflicts(): void
    {
        $w = $this->world();
        $void = $this->requestAsCashier($w, $this->ring($w)['id']);
        $reject = fn (string $reason) => $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/reject", ['reason' => $reason], $this->key());

        $reject('Not allowed')->assertOk();
        $reject('Not allowed')->assertOk()->assertJson(['status' => 'REJECTED']);
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_VOID_REJECTED')->count());

        $reject('A different reason')->assertStatus(409)->assertJson(['error' => ['code' => 'VOID_NOT_PENDING_APPROVAL']]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(409)->assertJson(['error' => ['code' => 'VOID_NOT_PENDING_APPROVAL']]);
    }

    public function test_a_rejection_needs_a_reason_and_a_key_and_a_rejected_void_can_be_requested_again(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $void = $this->requestAsCashier($w, $sale['id']);

        $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/reject", [], $this->key())
            ->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/reject", ['reason' => 'No'])
            ->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
        $this->assertSame('REQUESTED', SaleVoid::find($void['id'])->status);

        $this->asUser($w['manager'])->postJson("/api/v1/voids/{$void['id']}/reject", ['reason' => 'No'], $this->key())->assertOk();
        $again = $this->requestAsCashier($w, $sale['id'], 'Manager, please');
        $this->assertSame('REQUESTED', $again['status']);
        $this->assertNotSame($void['id'], $again['id']);
    }

    public function test_approving_an_already_voided_void_is_not_pending(): void
    {
        $w = $this->world();
        $void = $this->requestAsCashier($w, $this->ring($w)['id']);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())->assertOk();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$void['id']}/approve", [], $this->key())
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'VOID_NOT_PENDING_APPROVAL']]);
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
    }

    // ----------------------------------------------------------------- reads

    public function test_void_reads_are_scoped_filterable_and_need_only_a_session(): void
    {
        $w = $this->world();
        $pendingSale = $this->ring($w);
        $doneSale = $this->ring($w);
        $pending = $this->requestAsCashier($w, $pendingSale['id']);
        $done = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$doneSale['id']}/void", ['reason' => 'x'], $this->key())->json();
        $other = $this->world();
        $this->asUser($other['manager'], $other['enroll2'])->postJson("/api/v1/sales/{$this->ring($other)['id']}/void", ['reason' => 'x'], $this->key())->assertStatus(201);

        $list = fn (string $query = '') => $this->asUser($w['cashier'])->getJson('/api/v1/voids'.$query);

        $this->assertEqualsCanonicalizing([$pending['id'], $done['id']], collect($list()->json('data'))->pluck('id')->all());
        $this->assertSame([$pending['id']], collect($list('?status=REQUESTED')->json('data'))->pluck('id')->all());
        $this->assertSame([$done['id']], collect($list("?sale_id={$doneSale['id']}")->json('data'))->pluck('id')->all());
        $this->assertSame([], $list('?status=NOPE')->json('data'));
        $this->assertSame([], $list('?sale_id=not-a-uuid')->json('data'));
        $this->assertSame($pendingSale['id'], collect($list('?status=REQUESTED')->json('data'))->first()['sale_id']);

        $get = $this->asUser($w['cashier'])->getJson("/api/v1/voids/{$pending['id']}");
        $get->assertOk()->assertJson(['id' => $pending['id'], 'status' => 'REQUESTED', 'sale' => ['id' => $pendingSale['id']]]);

        $this->asUser($w['cashier'])->getJson('/api/v1/voids/'.(string) Str::uuid())->assertStatus(404)->assertJson(['error' => ['code' => 'VOID_NOT_FOUND']]);
        $foreign = SaleVoid::whereNotIn('id', [$pending['id'], $done['id']])->firstOrFail();
        $this->asUser($w['cashier'])->getJson("/api/v1/voids/{$foreign->id}")->assertStatus(404)->assertJson(['error' => ['code' => 'VOID_NOT_FOUND']]);
    }
}

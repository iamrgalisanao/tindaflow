<?php

namespace Tests\Database;

use App\Domain\Exceptions\StockEntryApprovalException;
use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\StockEntryGrant;
use App\Models\StockMovement;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Overrides\StockEntryApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsDailyStockScenario;

/**
 * ADR-017 slice B, invariants.md STK-004 and STK-007 (client decisions 5 and 6): who enters the day's cooked quantities and how. A manager or an
 * administrator enters directly; a cashier only with a manager's single-use approval (a code typed on the cashier's till), bound to terminal, cashier and
 * stock date, five minutes, audited with both ids and never carrying a code or a token into the log. Entry is per dish and per plain daily product, as a
 * total ("cooked today") or a second batch ("add"), written as added or removed portions in the canonical order, idempotently.
 */
class DailyStockEntryTest extends PostgresSchemaTestCase
{
    use BuildsDailyStockScenario;

    private const TODAY = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000, 'tindaflow.credit_override.throttle_attempts' => 1000]);
        $instant = CarbonImmutable::parse('2026-10-27 08:00:00', 'Asia/Manila');
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function canteen(): array
    {
        $w = $this->world();

        return [$w, $this->dailyMenu($w)];
    }

    /** The cashier at T1 asks the manager for an approval; returns the single-use token. */
    private function approval(array $w, ?User $approver = null, string $code = self::MANAGER_CODE): string
    {
        $approver ??= $w['manager'];
        $this->managerCode($approver);

        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $approver->id, 'code' => $code])
            ->assertStatus(201)->json('token');
    }

    private function cashierEnters(array $w, array $lines, ?string $token, ?array $headers = null)
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/daily-stock/entries', ['lines' => $lines] + ($token === null ? [] : ['stock_entry_token' => $token]), $headers ?? $this->key());
    }

    // ------------------------------------------------------------------ a manager enters directly

    public function test_a_manager_enters_the_days_quantities_directly_and_the_movements_record_who(): void
    {
        [$w, $m] = $this->canteen();

        $response = $this->enterCooked($w, [$m['nilaga']->id => '20', $m['pinakbet']->id => '12'])->assertStatus(201);

        $this->assertSame('20.000', $this->left($m['nilaga']));
        $this->assertSame('12.000', $this->left($m['pinakbet']));
        $movement = StockMovement::where('product_id', $m['nilaga']->fresh()->stock_product_id)->sole();
        $this->assertSame('DAILY_PORTIONS_ADDED', $movement->movement_type);
        $this->assertSame('20.000', (string) $movement->quantity);
        $this->assertSame(self::TODAY, $movement->stock_date->toDateString());
        $this->assertSame($w['manager']->id, $movement->created_by);
        $this->assertNull($movement->approved_by);
        $this->assertSame($w['t2']->id, $movement->terminal_id);

        $audit = AuditEvent::where('event_type', 'DAILY_STOCK_ENTERED')->sole();
        $this->assertSame($w['manager']->id, $audit->after_metadata['entered_by']);
        $this->assertNull($audit->after_metadata['approved_by']);
        $this->assertSame($audit->id, $movement->reference_id);
        $this->assertSame($audit->after_metadata['lines'][0]['cooked_after'], $response->json('lines.0.cooked_after'));
        $this->assertCount(2, $response->json('grid.rows') ? array_filter($response->json('grid.rows'), fn ($row) => $row['cooked'] !== '0.000') : []);
    }

    public function test_an_administrator_may_enter_too_and_a_plain_daily_product_is_entered_by_product(): void
    {
        [$w, $m] = $this->canteen();
        $siopao = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Siopao', 'stock_mode' => 'DAILY', 'selling_price' => '25.00']);

        $this->asUser($w['admin'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', ['lines' => [['product_id' => $siopao->id, 'cooked' => '30']]], $this->key())->assertStatus(201);

        $this->assertSame('30.000', bcadd((string) DB::table('daily_stock_balances')->where('product_id', $siopao->id)->value('quantity_on_hand'), '0', 3));
        $this->assertSame($w['admin']->id, StockMovement::where('product_id', $siopao->id)->sole()->created_by);
    }

    public function test_a_second_batch_adds_portions_and_the_cooked_total_is_the_sum(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '20'])->assertStatus(201);
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);

        $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', ['lines' => [['dish_id' => $m['nilaga']->id, 'add' => '5']]], $this->key())->assertStatus(201);

        $this->assertSame('24.000', $this->left($m['nilaga']));
        $grid = $this->asUser($w['manager'], $w['enroll2'])->getJson('/api/v1/daily-stock')->assertOk()->json('rows');
        $row = collect($grid)->firstWhere('id', $m['nilaga']->id);
        $this->assertSame(['25.000', '1.000', '24.000'], [$row['cooked'], $row['sold'], $row['remaining']]);
        $this->assertSame(2, StockMovement::where('movement_type', 'DAILY_PORTIONS_ADDED')->where('product_id', $m['nilaga']->fresh()->stock_product_id)->count());
    }

    public function test_setting_the_total_again_writes_only_the_difference_and_an_unchanged_total_writes_nothing(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '20'])->assertStatus(201);

        $this->enterCooked($w, [$m['nilaga']->id => '26'])->assertStatus(201);
        $this->assertSame('6.000', (string) StockMovement::where('movement_type', 'DAILY_PORTIONS_ADDED')->orderByDesc('occurred_at')->orderByDesc('id')->first()->quantity);

        $before = StockMovement::count();
        $this->enterCooked($w, [$m['nilaga']->id => '26'])->assertStatus(201);
        $this->assertSame($before, StockMovement::count());
        $this->assertSame('26.000', $this->left($m['nilaga']));
    }

    public function test_lowering_needs_a_reason_and_is_written_as_removed_portions(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '20'])->assertStatus(201);

        $this->enterCooked($w, [$m['nilaga']->id => '15'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['lines.0.cooked']]]);
        $this->assertSame('20.000', $this->left($m['nilaga']));

        $this->enterCooked($w, [$m['nilaga']->id => '15'], ['reason' => 'Burnt batch'])->assertStatus(201);
        $removed = StockMovement::where('movement_type', 'DAILY_PORTIONS_REMOVED')->sole();
        $this->assertSame(['5.000', 'Burnt batch', self::TODAY], [(string) $removed->quantity, $removed->reason, $removed->stock_date->toDateString()]);
        $this->assertSame('15.000', $this->left($m['nilaga']));
    }

    public function test_lowering_below_what_is_already_sold_is_refused_and_nothing_from_the_entry_is_written(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '5', $m['pinakbet']->id => '5'])->assertStatus(201);
        $this->sellItems($w, [$this->alaItem($m['alaNilaga'], '2')])->assertStatus(201); // 4 sold
        $before = StockMovement::count();

        $this->enterCooked($w, [$m['nilaga']->id => '3', $m['pinakbet']->id => '9'], ['reason' => 'Miscount'])->assertStatus(422)
            ->assertJsonPath('error.code', 'DAILY_STOCK_BELOW_SOLD')->assertJsonPath('error.details.items.0.name', 'Beef nilaga')
            ->assertJsonPath('error.details.items.0.sold', '4.000')->assertJsonPath('error.details.items.0.requested', '3.000');

        $this->assertSame($before, StockMovement::count(), 'the pinakbet line was not written either');
        $this->assertSame('5.000', $this->left($m['pinakbet']));
        $this->enterCooked($w, [$m['nilaga']->id => '4'], ['reason' => 'Exactly what is sold'])->assertStatus(201);
        $this->assertSame('0.000', $this->left($m['nilaga']));
    }

    public function test_only_dishes_and_products_that_count_daily_stock_can_be_entered_and_no_item_twice(): void
    {
        [$w, $m] = $this->canteen();
        $running = Product::factory()->create(['store_id' => $w['storeId']]);
        $manager = fn () => $this->asUser($w['manager'], $w['enroll2']);

        $manager()->postJson('/api/v1/daily-stock/entries', ['lines' => [['product_id' => $running->id, 'cooked' => '5']]], $this->key())->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['lines.0.product_id']]]);
        $manager()->postJson('/api/v1/daily-stock/entries', ['lines' => [['dish_id' => $m['nilaga']->id, 'cooked' => '5'], ['dish_id' => $m['nilaga']->id, 'add' => '1']]], $this->key())->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['lines.1.dish_id']]]);
        $manager()->postJson('/api/v1/daily-stock/entries', ['lines' => [['dish_id' => $m['nilaga']->id, 'cooked' => '5', 'add' => '1']]], $this->key())->assertStatus(422);
        $manager()->postJson('/api/v1/daily-stock/entries', ['lines' => [['dish_id' => $m['nilaga']->id, 'add' => '0']]], $this->key())->assertStatus(422);
        $manager()->postJson('/api/v1/daily-stock/entries', ['lines' => [['dish_id' => $m['nilaga']->id]]], $this->key())->assertStatus(422);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_entry_is_idempotent_the_same_key_is_not_counted_twice(): void
    {
        [$w, $m] = $this->canteen();
        $key = $this->key();
        $body = ['lines' => [['dish_id' => $m['nilaga']->id, 'add' => '7']]];

        $first = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', $body, $key)->assertStatus(201)->json('lines');
        $again = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', $body, $key)->assertStatus(201)->json('lines');

        $this->assertSame($first, $again);
        $this->assertSame('7.000', $this->left($m['nilaga']));
        $this->assertSame(1, AuditEvent::where('event_type', 'DAILY_STOCK_ENTERED')->count());
    }

    public function test_the_entry_needs_an_enrolled_terminal_and_an_idempotency_key_and_a_session(): void
    {
        [$w, $m] = $this->canteen();
        $body = ['lines' => [['dish_id' => $m['nilaga']->id, 'cooked' => '5']]];

        $this->asUser($w['manager'])->postJson('/api/v1/daily-stock/entries', $body, $this->key())->assertStatus(403);
        $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', $body)->assertStatus(400);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_entry_and_the_approval_need_a_session(): void
    {
        $this->postJson('/api/v1/daily-stock/entries', ['lines' => []], $this->key())->assertStatus(401);
        $this->postJson('/api/v1/daily-stock/approvals', [])->assertStatus(401);
        $this->getJson('/api/v1/daily-stock/approvers')->assertStatus(401);
    }

    // ------------------------------------------------------------------ a cashier needs a manager's code

    public function test_a_cashier_cannot_enter_stock_without_an_approval(): void
    {
        [$w, $m] = $this->canteen();

        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '20']], null)->assertStatus(403)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_REQUIRED');
        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '20']], bin2hex(random_bytes(32)))->assertStatus(422)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_INVALID');

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, DB::table('daily_stock_balances')->count());
    }

    public function test_a_cashier_enters_with_a_managers_code_and_both_are_recorded_and_no_code_or_token_is_logged(): void
    {
        [$w, $m] = $this->canteen();
        $token = $this->approval($w);

        $response = $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '18']], $token)->assertStatus(201);

        $this->assertSame('18.000', $this->left($m['nilaga']));
        $movement = StockMovement::where('movement_type', 'DAILY_PORTIONS_ADDED')->sole();
        $this->assertSame([$w['cashier']->id, $w['manager']->id], [$movement->created_by, $movement->approved_by]);
        $audit = AuditEvent::where('event_type', 'DAILY_STOCK_ENTERED')->sole();
        $this->assertSame([$w['cashier']->id, $w['manager']->id], [$audit->after_metadata['entered_by'], $audit->after_metadata['approved_by']]);
        $this->assertNotNull(StockEntryGrant::sole()->consumed_at);
        $this->assertSame($audit->after_metadata['approval_grant_id'], StockEntryGrant::sole()->id);
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_ENTRY_APPROVED')->count());

        // Not a code, not a token, not even a hash of one, in any audit line or in the answer.
        $everything = json_encode(AuditEvent::all()->toArray()).$response->getContent();
        $this->assertStringNotContainsString($token, $everything);
        $this->assertStringNotContainsString(hash('sha256', $token), $everything);
        $this->assertStringNotContainsString(self::MANAGER_CODE, $everything);
    }

    public function test_the_approval_is_single_use(): void
    {
        [$w, $m] = $this->canteen();
        $token = $this->approval($w);
        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '10']], $token)->assertStatus(201);

        $this->cashierEnters($w, [['dish_id' => $m['pinakbet']->id, 'cooked' => '10']], $token)->assertStatus(409)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_EXPIRED');

        $this->assertNull($this->left($m['pinakbet']));
        $this->assertSame(['ALREADY_USED'], AuditEvent::where('event_type', 'STOCK_ENTRY_APPROVAL_REFUSED')->pluck('reason')->all());
    }

    public function test_the_approval_expires_after_five_minutes(): void
    {
        [$w, $m] = $this->canteen();
        $token = $this->approval($w);

        $later = Carbon::now()->addMinutes(6);
        Carbon::setTestNow($later);
        CarbonImmutable::setTestNow($later);
        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '10']], $token)->assertStatus(409)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_EXPIRED');

        $this->assertNull(StockEntryGrant::sole()->consumed_at);
        $this->assertSame(['EXPIRED'], AuditEvent::where('event_type', 'STOCK_ENTRY_APPROVAL_REFUSED')->pluck('reason')->all());
    }

    public function test_a_failed_save_does_not_use_up_the_approval(): void
    {
        [$w, $m] = $this->canteen();
        $token = $this->approval($w);

        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '10'], ['dish_id' => $m['nilaga']->id, 'add' => '1']], $token)->assertStatus(422);
        $this->assertNull(StockEntryGrant::sole()->consumed_at, 'the entry rolled back, the approval is still good');

        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '10']], $token)->assertStatus(201);
    }

    public function test_a_cashier_with_an_approval_still_cannot_lower_below_sold(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '3'])->assertStatus(201);
        $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(201);

        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '1']], $this->approval($w))->assertStatus(422);
        $this->cashierEnters($w, [['dish_id' => $m['nilaga']->id, 'cooked' => '1']], $this->approval($w), null)->assertStatus(422);
    }

    public function test_the_approval_binds_terminal_cashier_and_stock_date_and_the_approver_must_still_be_eligible(): void
    {
        [$w, $m] = $this->canteen();
        $service = app(StockEntryApprovalService::class);
        $this->managerCode($w['manager']);
        $other = User::factory()->create(['store_id' => $w['storeId']]); // another cashier
        $grant = fn () => $service->grant($w['cashier'], $w['t1'], $w['manager']->id, self::MANAGER_CODE);
        $consume = function (array $given, ?Terminal $terminal = null, ?string $cashierId = null, string $date = self::TODAY) use ($service, $w) {
            return DB::transaction(fn () => $service->consume($service->lockByToken($given['token']), $terminal ?? $w['t1'], $cashierId ?? $w['cashier']->id, $date, now()));
        };
        $reason = function (callable $attempt): string {
            try {
                $attempt();
            } catch (StockEntryApprovalException $e) {
                return $e->reason;
            }

            return 'NO REFUSAL';
        };

        $this->assertSame('WRONG_TERMINAL', $reason(fn () => $consume($grant(), $w['t2'])));
        $this->assertSame('WRONG_CASHIER', $reason(fn () => $consume($grant(), null, $other->id)));
        $this->assertSame('WRONG_STOCK_DATE', $reason(fn () => $consume($grant(), null, null, '2026-10-28')));
        $this->assertSame('TOKEN_UNKNOWN', $reason(fn () => DB::transaction(fn () => $service->consume($service->lockByToken(bin2hex(random_bytes(32))), $w['t1'], $w['cashier']->id, self::TODAY, now()))));

        $demoted = $grant();
        $w['manager']->update(['active' => false]);
        $this->assertSame('APPROVER_NO_LONGER_ELIGIBLE', $reason(fn () => $consume($demoted)));
        $w['manager']->update(['active' => true]);
        $this->assertNotNull($consume($grant())->consumed_at, 'a good grant for the right till, cashier and day is consumed');
    }

    public function test_the_approver_must_hold_stock_adjust_and_differ_from_the_cashier(): void
    {
        [$w, $m] = $this->canteen();
        $this->managerCode($w['manager']);
        $another = User::factory()->create(['store_id' => $w['storeId']]);
        $till = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        $till()->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $another->id, 'code' => self::MANAGER_CODE])->assertStatus(422)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_INVALID');
        $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $w['manager']->id, 'code' => self::MANAGER_CODE])
            ->assertStatus(403)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_NOT_ALLOWED');
        $this->assertSame(0, StockEntryGrant::count());
        $this->assertSame(['APPROVER_LACKS_CAPABILITY', 'SELF_APPROVAL'], AuditEvent::where('event_type', 'STOCK_ENTRY_APPROVAL_REFUSED')->orderBy('occurred_at')->orderBy('id')->pluck('reason')->all());
    }

    public function test_five_wrong_codes_lock_the_manager_and_the_lock_is_shared_with_the_other_overrides(): void
    {
        [$w, $m] = $this->canteen();
        $this->stockRules($w, ['block_sale_without_stock' => true]);
        $this->managerCode($w['manager']);
        $till = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        for ($i = 1; $i <= 4; $i++) {
            $till()->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $w['manager']->id, 'code' => '000001'])->assertStatus(422)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_INVALID');
        }
        $till()->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $w['manager']->id, 'code' => '000001'])->assertStatus(423)->assertJsonPath('error.code', 'STOCK_ENTRY_APPROVAL_LOCKED');
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_ENTRY_APPROVAL_LOCKED')->count());

        // The right code is refused while locked, and the same lock stops the stock override of a blocked sale for this manager.
        $till()->postJson('/api/v1/daily-stock/approvals', ['approver_id' => $w['manager']->id, 'code' => self::MANAGER_CODE])->assertStatus(423);
        $till()->postJson('/api/v1/stock-overrides', ['approver_id' => $w['manager']->id, 'code' => self::MANAGER_CODE, 'items' => [['product_id' => $w['product']->id, 'quantity' => '1']]])->assertStatus(423);
        $this->assertSame(0, StockEntryGrant::count());
    }

    public function test_the_approver_list_is_names_only_and_holds_the_managers_with_a_code(): void
    {
        [$w, $m] = $this->canteen();
        $this->managerCode($w['manager']);

        $list = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/daily-stock/approvers')->assertOk()->json('data');

        $this->assertSame([['id' => $w['manager']->id, 'name' => $w['manager']->name]], $list);
    }

    public function test_the_grid_is_readable_by_a_cashier_and_shows_yesterdays_cooked_for_copying(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '20'])->assertStatus(201);

        $later = CarbonImmutable::parse('2026-10-28 08:00:00', 'Asia/Manila');
        Carbon::setTestNow($later);
        CarbonImmutable::setTestNow($later);
        $grid = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/daily-stock')->assertOk()->json();

        $this->assertFalse($grid['can_enter_directly']);
        $this->assertSame('2026-10-28', $grid['stock_date']);
        $row = collect($grid['rows'])->firstWhere('id', $m['nilaga']->id);
        $this->assertSame(['DISH', '0.000', '0.000', '20.000'], [$row['kind'], $row['cooked'], $row['remaining'], $row['yesterday_cooked']]);
        $this->assertSame('Beef', $row['group']);
    }

    public function test_the_grid_needs_a_session(): void
    {
        $this->getJson('/api/v1/daily-stock')->assertStatus(401);
    }
}

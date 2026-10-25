<?php

namespace Tests\Database;

use App\Domain\Exceptions\CreditOverrideException;
use App\Domain\Money;
use App\Models\AuditEvent;
use App\Models\CreditOverrideGrant;
use App\Models\Employee;
use App\Models\EmployeeCreditEntry;
use App\Models\Product;
use App\Models\User;
use App\Services\Overrides\CreditOverrideService;
use App\Services\Overrides\OverrideCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 3.3 (invariants.md OVR-001): a supervisor grant lets ONE over-limit credit sale through, consumed under the
 * employee lock inside the sale's transaction. The grant is bound to terminal, employee, cashier and a ceiling; it is consumed only by a sale
 * that really needs it; the next sale meets the limit again. The two-process replay is in CreditConcurrencyTest.
 */
class CreditOverrideCheckoutTest extends PostgresSchemaTestCase
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

    private function product(array $w, string $price): Product
    {
        return Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => $price, 'cost' => '1.00']);
    }

    private function employee(array $w, array $attributes = []): Employee
    {
        return Employee::factory()->create(['store_id' => $w['storeId']] + $attributes);
    }

    /** A manager with a code, and a grant from them for this cashier's till, via the real endpoint. */
    private function approval(array $w, Employee $employee, string $amount, ?User $approver = null): string
    {
        $approver ??= $w['manager'];
        if (! DB::table('user_override_codes')->where('user_id', $approver->id)->exists()) {
            app(OverrideCodeService::class)->setOwn($approver, 'password', self::CODE);
        }

        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $approver->id, 'code' => self::CODE, 'employee_id' => $employee->id, 'amount' => $amount,
        ])->assertStatus(201)->json('token');
    }

    private function charge(array $w, Employee $employee, Product $product, ?string $token = null, ?array $headers = null, string $quantity = '1'): TestResponse
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payments' => [['method' => 'CREDIT', 'amount' => bcmul((string) $product->selling_price, $quantity, 2)]],
            'employee_id' => $employee->id,
        ] + ($token === null ? [] : ['credit_override_token' => $token]), $headers ?? $this->key());
    }

    private function net(Employee $employee): string
    {
        return bcadd((string) DB::table('employee_credit_entries')->where('employee_id', $employee->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'CHARGE' THEN amount ELSE -amount END), 0) AS net")->value('net'), '0', 2);
    }

    private function refusalReasons(): array
    {
        return AuditEvent::where('event_type', 'CREDIT_OVERRIDE_REFUSED')->orderBy('occurred_at')->orderBy('id')->pluck('reason')->all();
    }

    public function test_an_over_limit_sale_goes_through_with_a_grant_and_the_charge_names_the_grant_and_counts_toward_the_period(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $big = $this->product($w, '1600.00');

        $this->charge($w, $employee, $big)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED');
        $this->assertSame(0, EmployeeCreditEntry::count());

        $token = $this->approval($w, $employee, '1600.00');
        $sale = $this->charge($w, $employee, $big, $token)->assertStatus(201)->json();

        $grant = CreditOverrideGrant::sole();
        $entry = EmployeeCreditEntry::where('sale_id', $sale['id'])->sole();
        $this->assertSame($grant->id, $entry->override_grant_id);
        $this->assertSame('1600.00', (string) $entry->amount);
        $this->assertNotNull($grant->fresh()->consumed_at);
        $this->assertSame('1600.00', $this->net($employee), 'the override charge counts toward the period');

        $used = AuditEvent::where('event_type', 'CREDIT_OVERRIDE_USED')->sole();
        $this->assertSame([$w['cashier']->id, $w['t1']->id, 'credit_override', $grant->id], [$used->actor_user_id, $used->terminal_id, $used->entity_type, $used->entity_id]);
        $this->assertEquals(
            ['sale_id' => $sale['id'], 'approver_id' => $w['manager']->id, 'cashier_id' => $w['cashier']->id, 'employee_id' => $employee->id, 'amount' => '1600.00', 'limit' => '1500.00', 'used' => '0.00', 'max_amount' => '1600.00'],
            array_intersect_key($used->after_metadata, array_flip(['sale_id', 'approver_id', 'cashier_id', 'employee_id', 'amount', 'limit', 'used', 'max_amount'])),
        );
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_events')->get()->all()));

        // The ordinary sale audit is unchanged.
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_FINALIZED')->where('entity_id', $sale['id'])->count());
    }

    public function test_the_approval_is_not_a_standing_exemption_the_next_sale_meets_the_limit_again_and_the_used_token_cannot_be_replayed(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $token = $this->approval($w, $employee, '1600.00');
        $this->charge($w, $employee, $this->product($w, '1600.00'), $token)->assertStatus(201);

        $this->charge($w, $employee, $this->product($w, '10.00'))->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED')
            ->assertJsonPath('error.details.used', '1600.00')->assertJsonPath('error.details.remaining', '-100.00');

        $replay = $this->charge($w, $employee, $this->product($w, '10.00'), $token)->assertStatus(409);
        $this->assertSame('CREDIT_OVERRIDE_EXPIRED', $replay->json('error.code'));
        $this->assertSame('1600.00', $this->net($employee));
        $this->assertSame(1, EmployeeCreditEntry::count());
        $this->assertSame(['ALREADY_USED'], $this->refusalReasons(), 'the refusal is audited even though the sale rolled back');
        $this->assertSame(1, AuditEvent::where('event_type', 'CREDIT_OVERRIDE_USED')->count());
    }

    public function test_a_sale_that_does_not_need_the_grant_does_not_consume_it(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $token = $this->approval($w, $employee, '1600.00');

        $within = $this->charge($w, $employee, $this->product($w, '100.00'), $token)->assertStatus(201)->json();

        $this->assertNull(CreditOverrideGrant::sole()->consumed_at);
        $this->assertNull(EmployeeCreditEntry::where('sale_id', $within['id'])->sole()->override_grant_id);
        $this->assertSame(0, AuditEvent::where('event_type', 'CREDIT_OVERRIDE_USED')->count());

        // It is still good for the sale that does need it (100 used + 1,500 = 1,600, past the limit, within the 1,600 ceiling).
        $this->charge($w, $employee, $this->product($w, '1500.00'), $token)->assertStatus(201);
        $this->assertNotNull(CreditOverrideGrant::sole()->consumed_at);
        $this->assertSame('1600.00', $this->net($employee));
    }

    public function test_the_grant_is_bound_to_the_employee_the_amount_the_time_and_the_approver_s_standing(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $other = $this->employee($w);
        $this->at('2026-10-10 12:00:00');

        // Another employee.
        $token = $this->approval($w, $employee, '1600.00');
        $refused = $this->charge($w, $other, $this->product($w, '1600.00'), $token)->assertStatus(403);
        $this->assertSame('CREDIT_OVERRIDE_NOT_ALLOWED', $refused->json('error.code'));

        // More than the ceiling it was given for (the ceiling is the sale total shown to the approver).
        $this->charge($w, $employee, $this->product($w, '1600.01'), $token)->assertStatus(403)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_NOT_ALLOWED');

        // Past its five minutes.
        $this->at('2026-10-10 12:05:01');
        $this->charge($w, $employee, $this->product($w, '1600.00'), $token)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_EXPIRED');
        $this->at('2026-10-10 12:06:00');

        // The approver stopped being able to approve after the grant: deactivated, then demoted.
        $second = $this->approval($w, $employee, '1600.00');
        $w['manager']->update(['active' => false]);
        $this->charge($w, $employee, $this->product($w, '1600.00'), $second)->assertStatus(403)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_NOT_ALLOWED');
        $w['manager']->update(['active' => true, 'role' => 'CASHIER']);
        $this->charge($w, $employee, $this->product($w, '1600.00'), $second)->assertStatus(403)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_NOT_ALLOWED');
        $w['manager']->update(['role' => 'MANAGER']);
        $this->charge($w, $employee, $this->product($w, '1600.00'), $second)->assertStatus(201);

        $this->assertSame(
            ['WRONG_EMPLOYEE', 'AMOUNT_ABOVE_CEILING', 'EXPIRED', 'APPROVER_NO_LONGER_ELIGIBLE', 'APPROVER_NO_LONGER_ELIGIBLE'],
            $this->refusalReasons(),
        );
        $this->assertSame(1, EmployeeCreditEntry::count());
    }

    public function test_a_grant_for_one_till_is_refused_on_another_and_for_another_cashier_and_an_unknown_token_is_just_invalid(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $token = $this->approval($w, $employee, '1600.00');
        $big = $this->product($w, '1600.00');

        // The manager's own till (terminal 2, their own shift, the same fiscal installation) cannot use the cashier's grant.
        $mapping = (array) DB::table('terminal_fiscal_installations')->where('terminal_id', $w['t1']->id)->first();
        DB::table('terminal_fiscal_installations')->insert(['id' => (string) Str::uuid(), 'terminal_id' => $w['t2']->id] + array_diff_key($mapping, ['id' => 1, 'terminal_id' => 1]));
        $refused = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $big->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '1600.00']],
            'employee_id' => $employee->id, 'credit_override_token' => $token,
        ], $this->key())->assertStatus(403);
        $this->assertSame('CREDIT_OVERRIDE_NOT_ALLOWED', $refused->json('error.code'));

        $this->charge($w, $employee, $big, str_repeat('a', 64))->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_INVALID');
        $this->charge($w, $employee, $big, 'not-a-token')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // The cashier binding itself, below the HTTP layer: the same grant offered for a different cashier id.
        DB::beginTransaction();
        try {
            app(CreditOverrideService::class)->consume($token, $w['t1'], $w['admin']->id, $employee, new Money('1600.00'), now());
            $this->fail('a grant must not be usable for another cashier');
        } catch (CreditOverrideException $e) {
            $this->assertSame(['CREDIT_OVERRIDE_NOT_ALLOWED', 'WRONG_CASHIER'], [$e->errorCode(), $e->reason]);
        } finally {
            DB::rollBack();
        }

        $this->assertNull(CreditOverrideGrant::sole()->consumed_at);
        $this->assertSame(['WRONG_TERMINAL', 'TOKEN_UNKNOWN'], $this->refusalReasons());
        $this->assertSame(0, EmployeeCreditEntry::count());
    }

    public function test_a_token_on_a_sale_that_is_not_a_credit_sale_is_refused(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $token = $this->approval($w, $employee, '200.00');

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            'credit_override_token' => $token,
        ], $this->key())->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_EMPLOYEE_REQUIRED');
        $this->assertNull(CreditOverrideGrant::sole()->consumed_at);
    }

    public function test_replaying_the_same_sale_returns_it_without_consuming_a_second_time_and_the_token_is_part_of_the_request_hash(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $big = $this->product($w, '1600.00');
        $token = $this->approval($w, $employee, '1600.00');
        $key = $this->key();

        $first = $this->charge($w, $employee, $big, $token, $key)->assertStatus(201)->json();
        $again = $this->charge($w, $employee, $big, $token, $key)->assertStatus(201)->json();

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, EmployeeCreditEntry::count());
        $this->assertSame(1, AuditEvent::where('event_type', 'CREDIT_OVERRIDE_USED')->count());
        $this->assertSame([], $this->refusalReasons());

        // The same key with a different token (or none) is a different request, never a replay.
        $otherEmployee = $this->employee($w);
        $otherToken = $this->approval($w, $otherEmployee, '1600.00');
        $this->charge($w, $employee, $big, $otherToken, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->charge($w, $employee, $big, null, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, EmployeeCreditEntry::count());
        $this->assertNull(CreditOverrideGrant::where('employee_id', $otherEmployee->id)->sole()->consumed_at);
    }

    public function test_voiding_an_override_sale_gives_the_whole_charge_back_and_does_not_restore_the_grant(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $big = $this->product($w, '1600.00');
        $token = $this->approval($w, $employee, '1600.00');
        $sale = $this->charge($w, $employee, $big, $token)->assertStatus(201)->json();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);

        $this->assertSame('0.00', $this->net($employee));
        $this->assertSame(['CHARGE', 'REVERSAL'], EmployeeCreditEntry::orderBy('recorded_at')->orderBy('entry_type')->pluck('entry_type')->all());
        $this->assertNull(EmployeeCreditEntry::where('entry_type', 'REVERSAL')->sole()->override_grant_id);
        $this->assertNotNull(CreditOverrideGrant::sole()->consumed_at, 'a consumed approval stays consumed');
    }

    public function test_an_override_cannot_open_a_door_the_limit_check_did_not_close_a_sale_that_fails_for_another_reason_leaves_the_grant_unused(): void
    {
        $w = $this->world();
        $inactive = $this->employee($w);
        $token = $this->approval($w, $inactive, '1600.00');
        $inactive->update(['active' => false]);

        $this->charge($w, $inactive, $this->product($w, '1600.00'), $token)->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYEE_INACTIVE');
        $this->assertNull(CreditOverrideGrant::sole()->consumed_at);
    }

    private function at(string $manila): void
    {
        $instant = CarbonImmutable::parse($manila, 'Asia/Manila');
        CarbonImmutable::setTestNow($instant);
        Carbon::setTestNow(Carbon::instance($instant));
    }
}

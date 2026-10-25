<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\CreditOverrideGrant;
use App\Models\Employee;
use App\Models\Store;
use App\Models\User;
use App\Models\UserOverrideCode;
use App\Services\Overrides\OverrideCodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 3.3 (invariants.md OVR-001, OVR-002): an approver's override code and the single-use grant a right code
 * issues. Built through the real endpoints. Consuming a grant at checkout is CreditOverrideCheckoutTest; that a wrong-code count is really
 * COMMITTED (the suites here run inside a rolled-back transaction) is CreditOverrideLockoutTest; the parallel replay is CreditConcurrencyTest.
 */
class CreditOverrideHttpTest extends PostgresSchemaTestCase
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
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $w */
    private function setCode(User $approver, string $code = self::CODE): void
    {
        app(OverrideCodeService::class)->setOwn($approver, 'password', $code);
    }

    /** @param  array<string, mixed>  $w */
    private function employee(array $w, array $attributes = []): Employee
    {
        return Employee::factory()->create(['store_id' => $w['storeId']] + $attributes);
    }

    /** @param  array<string, mixed>  $w */
    private function grant(array $w, string $approverId, string $code, string $employeeId, string $amount = '200.00'): TestResponse
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $approverId, 'code' => $code, 'employee_id' => $employeeId, 'amount' => $amount,
        ]);
    }

    private function counter(User $approver): int
    {
        return (int) DB::table('user_override_codes')->where('user_id', $approver->id)->value('failed_attempts');
    }

    // ------------------------------------------------------------------ the approver list

    public function test_the_till_lists_the_approvers_who_hold_a_code_by_name_only(): void
    {
        $w = $this->world();
        $withoutCode = User::factory()->manager()->create(['store_id' => $w['storeId'], 'name' => 'Nina NoCode']);
        $inactive = User::factory()->manager()->inactive()->create(['store_id' => $w['storeId'], 'name' => 'Ivy Inactive']);
        $otherStore = User::factory()->manager()->create(['store_id' => Store::factory()->create()->id, 'name' => 'Olga Other']);
        $this->setCode($w['manager']);
        $this->setCode($w['admin']);
        $this->setCode($inactive, '739152');
        $this->setCode($otherStore, '615243');
        // A cashier somehow holding a row (a demoted manager) is not an approver.
        $demoted = User::factory()->manager()->create(['store_id' => $w['storeId'], 'name' => 'Dan Demoted']);
        $this->setCode($demoted, '846213');
        $demoted->update(['role' => 'CASHIER']);

        $response = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/credit-override-approvers')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertEqualsCanonicalizing([$w['manager']->name, $w['admin']->name], $names);
        $this->assertNotContains($withoutCode->name, $names);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')), 'names and ids only: no role, email, code or status');
        $this->assertStringNotContainsString(self::CODE, $response->getContent());

        // A manager who is signed in is not offered themselves.
        $this->asUser($w['manager'])->getJson('/api/v1/credit-override-approvers')->assertOk()
            ->assertJsonMissing(['id' => $w['manager']->id]);
    }

    // ------------------------------------------------------------------ setting a code

    public function test_an_approver_sets_their_own_code_after_re_entering_their_password_and_only_a_hash_is_stored(): void
    {
        $w = $this->world();

        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => self::CODE])
            ->assertOk()->assertJson(['user_id' => $w['manager']->id, 'has_code' => true, 'locked' => false]);

        $stored = (string) DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('code_hash');
        $this->assertNotSame(self::CODE, $stored);
        $this->assertTrue(Hash::check(self::CODE, $stored));
        $this->assertSame($w['manager']->id, DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('set_by'));

        $this->asUser($w['manager'])->getJson('/api/v1/credit-override-code')->assertOk()->assertJson(['has_code' => true])
            ->assertJsonMissing(['code_hash' => $stored]);

        $event = AuditEvent::where('event_type', 'OVERRIDE_CODE_SET')->sole();
        $this->assertSame($w['manager']->id, $event->actor_user_id);
        $this->assertSame(['has_code' => false], $event->before_metadata);
        $this->assertStringNotContainsString(self::CODE, json_encode($event->getAttributes()));
        $this->assertStringNotContainsString($stored, json_encode($event->getAttributes()));

        // Changing it later records that a code already existed.
        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => '739152'])->assertOk();
        $this->assertSame(['has_code' => true], AuditEvent::where('event_type', 'OVERRIDE_CODE_SET')->orderBy('occurred_at')->orderBy('id')->get()->last()->before_metadata ?? null);
        $this->assertTrue(Hash::check('739152', (string) DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('code_hash')));
    }

    public function test_setting_a_code_needs_the_right_password_a_decent_code_and_a_code_unlike_the_password(): void
    {
        $w = $this->world();
        $numeric = User::factory()->manager()->create(['store_id' => $w['storeId']]);
        $put = fn (User $user, array $body) => $this->asUser($user)->putJson('/api/v1/credit-override-code', $body);

        $put($w['manager'], ['password' => 'wrong', 'code' => self::CODE])->assertStatus(422)->assertJsonPath('error.details.password.0', 'That password is not right.');
        foreach (['12345', '123456789', '12ab56', '12 456', ''] as $shape) {
            $put($w['manager'], ['password' => 'password', 'code' => $shape])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['code']]]);
        }
        foreach (['111111', '000000', '12345678', '234567', '654321', '987654', '890123', '121212', '123123', '12341234'] as $weak) {
            $put($w['manager'], ['password' => 'password', 'code' => $weak])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $acting = $this->asUser($numeric);
        $numeric->forceFill(['password_hash' => Hash::make('90817265')])->save(); // an all-digit password, changed after sign-in
        $acting->putJson('/api/v1/credit-override-code', ['password' => '90817265', 'code' => '90817265'])->assertStatus(422)
            ->assertJsonPath('error.details.code.0', 'The code must be different from your account password.');

        $this->assertSame(0, UserOverrideCode::count(), 'nothing was stored by any refusal');
        $this->assertSame(0, AuditEvent::where('event_type', 'OVERRIDE_CODE_SET')->count());
        $put($w['manager'], ['password' => 'password', 'code' => self::CODE])->assertOk();
    }

    public function test_a_cashier_cannot_hold_or_set_a_code_and_the_endpoints_are_for_the_signed_in_user_only(): void
    {
        $w = $this->world();

        $this->asUser($w['cashier'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => self::CODE])->assertStatus(403);
        $this->asUser($w['cashier'])->getJson('/api/v1/credit-override-code')->assertStatus(403);
        $this->asUser($w['manager'])->getJson('/api/v1/credit-override-codes')->assertStatus(403);
        $this->assertSame(0, UserOverrideCode::count());
    }

    public function test_setting_a_code_is_throttled_per_user(): void
    {
        $w = $this->world();
        config(['tindaflow.credit_override.throttle_attempts' => 3]);
        $acting = $this->asUser($w['manager']);

        for ($i = 0; $i < 3; $i++) {
            $acting->putJson('/api/v1/credit-override-code', ['password' => 'nope', 'code' => self::CODE])->assertStatus(422);
        }
        $acting->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => self::CODE])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertSame(0, UserOverrideCode::count());
    }

    // ------------------------------------------------------------------ an administrator clears a code

    public function test_an_administrator_resets_a_code_after_their_own_password_and_the_reset_is_audited_and_unlocks(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        DB::table('user_override_codes')->where('user_id', $w['manager']->id)->update(['failed_attempts' => 3, 'locked_until' => now()->addMinutes(10)]);

        $list = $this->asUser($w['admin'])->getJson('/api/v1/credit-override-codes')->assertOk()->json('data');
        $row = collect($list)->firstWhere('user_id', $w['manager']->id);
        $this->assertTrue($row['has_code']);
        $this->assertTrue($row['locked']);

        $this->asUser($w['admin'])->postJson("/api/v1/users/{$w['manager']->id}/override-code/reset", ['password' => 'wrong'])->assertStatus(422);
        $this->assertTrue((bool) DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('code_hash'));

        $this->asUser($w['admin'])->postJson("/api/v1/users/{$w['manager']->id}/override-code/reset", ['password' => 'password'])
            ->assertOk()->assertJson(['has_code' => false, 'locked' => false]);

        $this->assertNull(DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('code_hash'));
        $this->assertSame([0, null], [$this->counter($w['manager']), DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('locked_until')]);

        $event = AuditEvent::where('event_type', 'OVERRIDE_CODE_RESET')->sole();
        $this->assertSame([$w['admin']->id, 'user', $w['manager']->id], [$event->actor_user_id, $event->entity_type, $event->entity_id]);
        $this->assertEquals(['has_code' => true, 'locked' => true], $event->before_metadata);
        $this->assertSame($w['admin']->id, $event->after_metadata['reset_by']);

        // The cleared approver is gone from the till's list until they set a new code, and cannot approve with the old one.
        $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/credit-override-approvers')->assertOk()->assertJsonMissing(['id' => $w['manager']->id]);
        $this->grant($w, $w['manager']->id, self::CODE, $this->employee($w)->id)->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_INVALID');

        // A manager cannot reset anyone's code, and an administrator cannot reach into another store.
        $this->asUser($w['manager'])->postJson("/api/v1/users/{$w['admin']->id}/override-code/reset", ['password' => 'password'])->assertStatus(403);
        $foreign = User::factory()->manager()->create(['store_id' => Store::factory()->create()->id]);
        $this->asUser($w['admin'])->postJson("/api/v1/users/{$foreign->id}/override-code/reset", ['password' => 'password'])->assertStatus(404);
        $this->assertSame(1, AuditEvent::where('event_type', 'OVERRIDE_CODE_RESET')->count());
    }

    // ------------------------------------------------------------------ a right code issues a grant

    public function test_a_right_code_issues_a_single_use_grant_bound_to_the_till_the_cashier_the_employee_and_the_amount_and_only_a_hash_of_the_token_is_kept(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);
        $this->travelTo(now()->startOfSecond());

        $response = $this->grant($w, $w['manager']->id, self::CODE, $employee->id, '1600.00')->assertStatus(201);
        $token = $response->json('token');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame('1600.00', $response->json('max_amount'));
        $this->assertSame(['id' => $w['manager']->id, 'name' => $w['manager']->name], $response->json('approver'));
        $this->assertSame(now()->addMinutes(5)->toIso8601String(), Carbon::parse($response->json('expires_at'))->toIso8601String());

        $grant = CreditOverrideGrant::findOrFail($response->json('grant_id'));
        $this->assertSame(
            [$w['t1']->id, $employee->id, $w['cashier']->id, $w['manager']->id, '1600.00', $w['storeId']],
            [$grant->terminal_id, $grant->employee_id, $grant->cashier_id, $grant->approver_id, (string) $grant->max_amount, $grant->store_id],
        );
        $this->assertNull($grant->consumed_at);
        $this->assertSame(hash('sha256', $token), DB::table('credit_override_grants')->where('id', $grant->id)->value('token_hash'));
        $this->assertSame(0, DB::table('credit_override_grants')->whereRaw('token_hash = ?', [$token])->count());

        $event = AuditEvent::where('event_type', 'CREDIT_OVERRIDE_GRANTED')->sole();
        $this->assertSame([$w['cashier']->id, $w['t1']->id, 'credit_override', $grant->id], [$event->actor_user_id, $event->terminal_id, $event->entity_type, $event->entity_id]);
        $this->assertEquals(
            ['approver_id' => $w['manager']->id, 'cashier_id' => $w['cashier']->id, 'employee_id' => $employee->id, 'limit' => '1500.00', 'used' => '0.00'],
            array_intersect_key($event->after_metadata, array_flip(['approver_id', 'cashier_id', 'employee_id', 'limit', 'used'])),
        );
        $this->assertSame(0, DB::table('audit_events')->whereRaw('after_metadata::text LIKE ?', ['%'.$token.'%'])->count());
        $this->assertSame(0, DB::table('audit_events')->whereRaw('after_metadata::text LIKE ?', ['%'.self::CODE.'%'])->count());
        $this->assertSame(0, $this->counter($w['manager']));
    }

    public function test_an_admin_can_approve_too_and_the_amount_must_be_a_positive_peso_figure(): void
    {
        $w = $this->world();
        $this->setCode($w['admin']);
        $employee = $this->employee($w);

        $this->grant($w, $w['admin']->id, self::CODE, $employee->id)->assertStatus(201);
        foreach (['0.00', '-5.00', '200', '2e2', 'abc'] as $bad) {
            $this->grant($w, $w['admin']->id, self::CODE, $employee->id, $bad)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    // ------------------------------------------------------------------ the refusals

    public function test_a_wrong_code_a_missing_approver_an_inactive_one_one_without_a_code_or_the_capability_all_get_the_same_generic_answer(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);
        $inactive = User::factory()->manager()->create(['store_id' => $w['storeId']]);
        $this->setCode($inactive, '739152');
        $inactive->update(['active' => false]);
        $noCode = User::factory()->manager()->create(['store_id' => $w['storeId']]);
        $cashierWithRow = User::factory()->create(['store_id' => $w['storeId']]);
        UserOverrideCode::create(['user_id' => $cashierWithRow->id, 'store_id' => $w['storeId'], 'code_hash' => Hash::make('846213')]);
        $otherStore = User::factory()->manager()->create(['store_id' => Store::factory()->create()->id]);
        $this->setCode($otherStore, '615243');

        $answers = [
            'wrong code' => $this->grant($w, $w['manager']->id, '000001', $employee->id),
            'unknown approver' => $this->grant($w, '0194b2c0-0000-7000-8000-000000000000', self::CODE, $employee->id),
            'inactive approver' => $this->grant($w, $inactive->id, '739152', $employee->id),
            'no code' => $this->grant($w, $noCode->id, self::CODE, $employee->id),
            'cashier role' => $this->grant($w, $cashierWithRow->id, '846213', $employee->id),
            'another store' => $this->grant($w, $otherStore->id, '615243', $employee->id),
        ];

        foreach ($answers as $which => $answer) {
            $answer->assertStatus(422);
            $this->assertSame('CREDIT_OVERRIDE_INVALID', $answer->json('error.code'), $which);
            $this->assertSame('That code did not work.', $answer->json('error.message'), $which);
            $this->assertSame([], $answer->json('error.details'), $which);
        }
        $this->assertSame(0, CreditOverrideGrant::count());

        // Each one is audited with its own reason, which the client never sees.
        $reasons = AuditEvent::where('event_type', 'CREDIT_OVERRIDE_REFUSED')->orderBy('occurred_at')->orderBy('id')->pluck('reason')->all();
        $this->assertEqualsCanonicalizing(['WRONG_CODE', 'UNKNOWN_APPROVER', 'APPROVER_INACTIVE', 'NO_CODE', 'APPROVER_LACKS_CAPABILITY', 'UNKNOWN_APPROVER'], $reasons);
        $this->assertSame(1, $this->counter($w['manager']), 'only the real wrong code was counted');
    }

    public function test_the_cashier_cannot_approve_their_own_sale(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);

        // The manager works a till of their own (terminal 2) and tries to approve their own over-limit sale.
        $own = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $w['manager']->id, 'code' => self::CODE, 'employee_id' => $employee->id, 'amount' => '1600.00',
        ])->assertStatus(403);

        $this->assertSame('CREDIT_OVERRIDE_NOT_ALLOWED', $own->json('error.code'));
        $this->assertSame(0, CreditOverrideGrant::count());
        $this->assertSame(0, $this->counter($w['manager']), 'the right code was never even tried');
        $this->assertSame('SELF_APPROVAL', AuditEvent::where('event_type', 'CREDIT_OVERRIDE_REFUSED')->sole()->reason);
        $this->assertSame(0, DB::table('credit_override_grants')->count());
    }

    public function test_the_employee_must_be_an_active_employee_of_the_store_and_the_till_must_be_enrolled(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);

        $this->grant($w, $w['manager']->id, self::CODE, $this->employee($w, ['active' => false])->id)->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYEE_INACTIVE');
        $this->grant($w, $w['manager']->id, self::CODE, Employee::factory()->create(['store_id' => Store::factory()->create()->id])->id)->assertStatus(404);
        $this->grant($w, $w['manager']->id, self::CODE, '0194b2c0-0000-7000-8000-000000000000')->assertStatus(404);
        $this->asUser($w['cashier'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $w['manager']->id, 'code' => self::CODE, 'employee_id' => $this->employee($w)->id, 'amount' => '200.00',
        ])->assertStatus(403)->assertJsonPath('error.code', 'TERMINAL_NOT_ENROLLED');
        $this->assertSame(0, CreditOverrideGrant::count());
        $this->assertSame(0, $this->counter($w['manager']));
    }

    // ------------------------------------------------------------------ the lockout

    public function test_five_wrong_codes_lock_the_approver_for_fifteen_minutes_even_for_the_right_code_and_the_lock_then_expires(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $this->setCode($w['admin'], '739152');
        $employee = $this->employee($w);
        $this->travelTo(now()->startOfSecond());

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->grant($w, $w['manager']->id, '000001', $employee->id)->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_INVALID');
            $this->assertSame($attempt, $this->counter($w['manager']));
        }
        $fifth = $this->grant($w, $w['manager']->id, '000001', $employee->id)->assertStatus(423);
        $this->assertSame('CREDIT_OVERRIDE_LOCKED', $fifth->json('error.code'));
        $this->assertSame(15, $fifth->json('error.details.retry_after_minutes'));
        $this->assertSame(now()->addMinutes(15)->toIso8601String(), Carbon::parse(DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('locked_until'))->toIso8601String());

        // The right code is refused while locked, and does not extend the lock; another approver is unaffected.
        $this->travelTo(now()->addMinutes(14)->addSeconds(30));
        $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->assertStatus(423)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_LOCKED');
        $this->grant($w, $w['admin']->id, '739152', $employee->id)->assertStatus(201);
        $this->assertSame(0, CreditOverrideGrant::where('approver_id', $w['manager']->id)->count());

        $this->travelTo(now()->addSeconds(31));
        $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->assertStatus(201);
        $this->assertSame(0, $this->counter($w['manager']));

        $events = AuditEvent::whereIn('event_type', ['CREDIT_OVERRIDE_REFUSED', 'CREDIT_OVERRIDE_LOCKED'])->get();
        $this->assertSame(5 + 1, $events->where('event_type', 'CREDIT_OVERRIDE_REFUSED')->count());
        $this->assertSame(1, $events->where('event_type', 'CREDIT_OVERRIDE_LOCKED')->count());
        $this->assertSame($w['manager']->id, $events->firstWhere('event_type', 'CREDIT_OVERRIDE_LOCKED')->entity_id);
        $this->assertContains('APPROVER_LOCKED', $events->pluck('reason')->all());
    }

    public function test_a_right_code_clears_the_wrong_code_count_so_the_lock_needs_five_in_a_row(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);

        for ($i = 0; $i < 4; $i++) {
            $this->grant($w, $w['manager']->id, '000001', $employee->id)->assertStatus(422);
        }
        $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->assertStatus(201);
        $this->assertSame(0, $this->counter($w['manager']));
        for ($i = 0; $i < 4; $i++) {
            $this->grant($w, $w['manager']->id, '000001', $employee->id)->assertStatus(422);
        }
        $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->assertStatus(201);
    }

    public function test_an_approver_can_clear_their_own_lock_by_setting_a_code_with_their_password(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        DB::table('user_override_codes')->where('user_id', $w['manager']->id)->update(['failed_attempts' => 0, 'locked_until' => now()->addMinutes(10)]);

        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => '739152'])->assertOk()->assertJson(['locked' => false]);
        $this->grant($w, $w['manager']->id, '739152', $this->employee($w)->id)->assertStatus(201);
    }

    public function test_the_verify_endpoint_is_throttled_per_terminal(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);
        config(['tindaflow.credit_override.throttle_attempts' => 3, 'tindaflow.credit_override.max_wrong_codes' => 50]);

        for ($i = 0; $i < 3; $i++) {
            $this->grant($w, $w['manager']->id, '000001', $employee->id)->assertStatus(422);
        }
        $blocked = $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->assertStatus(429);
        $this->assertSame('RATE_LIMITED', $blocked->json('error.code'));
        $this->assertSame(0, CreditOverrideGrant::count());
        $this->assertSame(3, $this->counter($w['manager']), 'a throttled request is not even checked');
    }

    public function test_no_code_or_token_ever_appears_in_any_audit_payload(): void
    {
        $w = $this->world();
        $this->setCode($w['manager']);
        $employee = $this->employee($w);
        $token = $this->grant($w, $w['manager']->id, self::CODE, $employee->id)->json('token');
        for ($i = 0; $i < 5; $i++) {
            $this->grant($w, $w['manager']->id, '135790', $employee->id);
        }
        $this->grant($w, $w['manager']->id, self::CODE, $employee->id);
        $this->asUser($w['admin'])->postJson("/api/v1/users/{$w['manager']->id}/override-code/reset", ['password' => 'password'])->assertOk();

        $dump = json_encode(DB::table('audit_events')->get()->all());
        foreach ([self::CODE, '135790', $token, hash('sha256', $token)] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(
            ['CREDIT_OVERRIDE_GRANTED', 'CREDIT_OVERRIDE_LOCKED', 'CREDIT_OVERRIDE_REFUSED', 'OVERRIDE_CODE_RESET', 'OVERRIDE_CODE_SET'],
            DB::table('audit_events')->where(fn ($q) => $q->where('event_type', 'like', 'CREDIT_OVERRIDE%')->orWhere('event_type', 'like', 'OVERRIDE_CODE%'))->distinct()->orderBy('event_type')->pluck('event_type')->all(),
        );
    }
}

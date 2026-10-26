<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\BillingParty;
use App\Models\Employee;
use App\Models\Store;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * ADR-016 slice 3.1 (EMP-001): employees with generated, immutable codes, the credit limit per type, the CSV import, the ID-card
 * data and the till's exact-code lookup. EMPLOYEE_MANAGE (ADMIN and MANAGER) gates everything but the lookup, and EMPLOYEE_POLICY_MANAGE (ADMIN only) gates the credit-limit change and the CSV import; everything is store scoped;
 * nothing is deleted. The gap-free guarantee under real concurrency is EmployeeCodeConcurrencyTest; the database role is
 * AppendOnlyPrivilegesTest.
 */
class EmployeeHttpTest extends PostgresSchemaTestCase
{
    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + ['employee_type' => 'DIRECT', 'first_name' => 'Maria', 'last_name' => 'Santos'];
    }

    private function importCsv(User $user, string $csv, bool $dryRun = false): TestResponse
    {
        return $this->asUser($user)->call(
            'POST',
            '/api/v1/employees/import'.($dryRun ? '?dry_run=true' : ''),
            [], [], [],
            ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'],
            $csv,
        );
    }

    private function events(string $type): Collection
    {
        return AuditEvent::where('event_type', $type)->orderBy('occurred_at')->get();
    }

    // ---------------------------------------------------------------- create and the code

    public function test_an_admin_creates_employees_and_the_server_generates_gap_free_codes_per_type(): void
    {
        $admin = User::factory()->admin()->create();

        $first = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => '  Maria   Cristina ', 'middle_name' => ' Reyes ', 'last_name' => 'Santos']));
        $first->assertStatus(201)->assertJson([
            'employee_code' => 'TSC00001', 'employee_type' => 'DIRECT', 'first_name' => 'Maria Cristina', 'middle_name' => 'Reyes',
            'last_name' => 'Santos', 'name' => 'Maria Cristina Reyes Santos', 'active' => true,
        ]);
        $second = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Jose', 'last_name' => 'Cruz']));
        $sub = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['employee_type' => 'SUBCONTRACTOR', 'first_name' => 'Ramon', 'last_name' => 'Diaz', 'middle_name' => '']));

        $this->assertSame('TSC00002', $second->json('employee_code'));
        $this->assertSame('SUB00001', $sub->json('employee_code'));
        $this->assertNull($sub->json('middle_name'));
        $this->assertSame($admin->store_id, Employee::findOrFail($first->json('id'))->store_id);
    }

    public function test_the_code_grows_past_five_digits_instead_of_failing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->assertStatus(201);
        DB::table('employee_code_sequences')->where('store_id', $admin->store_id)->update(['last_number' => 99999]);

        $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Next']))->assertStatus(201)->assertJson(['employee_code' => 'TSC100000']);
        $this->assertSame('TSC100001', EmployeeService::normalizeCode(' tsc100001 '));
    }

    public function test_the_client_cannot_send_a_code_and_the_type_must_be_known(): void
    {
        $admin = User::factory()->admin()->create();

        $withCode = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['employee_code' => 'TSC00500']));
        $withCode->assertStatus(422);
        $this->assertArrayHasKey('employee_code', $withCode->json('error.details'));
        $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['employee_type' => 'MANAGER']))->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => '   ']))->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Solo'])->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, (int) DB::table('employee_code_sequences')->sum('last_number'), 'a refused request must not burn a number');
    }

    public function test_a_creation_that_rolls_back_gives_its_number_back_so_codes_stay_gap_free(): void
    {
        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->assertStatus(201);

        try {
            DB::transaction(function () use ($admin) {
                app(EmployeeService::class)->create($admin, $this->input(['first_name' => 'Doomed']));
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Next']))->assertStatus(201)->assertJson(['employee_code' => 'TSC00002']);
        $this->assertSame(0, Employee::where('first_name', 'Doomed')->count());
    }

    public function test_a_code_is_never_reused_after_the_employee_is_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $gone = $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->assertStatus(201);
        $this->asUser($admin)->postJson("/api/v1/employees/{$gone->json('id')}/deactivate")->assertOk();

        $next = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Replacement']));

        $this->assertSame('TSC00002', $next->json('employee_code'));
        $this->assertSame('TSC00001', Employee::findOrFail($gone->json('id'))->employee_code, 'the deactivated employee keeps the code');
    }

    public function test_every_employee_is_billed_to_the_one_party_named_tsc_and_the_party_is_created_for_a_new_store(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(0, BillingParty::where('store_id', $admin->store_id)->count());

        $direct = $this->asUser($admin)->postJson('/api/v1/employees', $this->input());
        $sub = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['employee_type' => 'SUBCONTRACTOR', 'first_name' => 'Sub']));

        $parties = BillingParty::where('store_id', $admin->store_id)->get();
        $this->assertCount(1, $parties);
        $this->assertSame('TSC', $parties->first()->name);
        $this->assertSame($parties->first()->id, $direct->json('billing_party_id'));
        $this->assertSame($parties->first()->id, $sub->json('billing_party_id'));
    }

    // ---------------------------------------------------------------- edit, deactivate, audit

    public function test_names_can_be_edited_but_the_code_and_type_cannot_and_nothing_is_recorded_for_a_no_op(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->json('id');

        $this->asUser($admin)->patchJson("/api/v1/employees/{$id}", ['first_name' => ' Marie ', 'middle_name' => 'Lopez'])
            ->assertOk()->assertJson(['first_name' => 'Marie', 'middle_name' => 'Lopez', 'employee_code' => 'TSC00001', 'name' => 'Marie Lopez Santos']);

        $code = $this->asUser($admin)->patchJson("/api/v1/employees/{$id}", ['employee_code' => 'TSC77777']);
        $code->assertStatus(422);
        $this->assertArrayHasKey('employee_code', $code->json('error.details'));
        $type = $this->asUser($admin)->patchJson("/api/v1/employees/{$id}", ['employee_type' => 'SUBCONTRACTOR']);
        $type->assertStatus(422);
        $this->assertSame('TSC00001', Employee::findOrFail($id)->employee_code);
        $this->assertSame('DIRECT', Employee::findOrFail($id)->employee_type->value);

        $this->asUser($admin)->patchJson("/api/v1/employees/{$id}", ['first_name' => 'Marie'])->assertOk();

        $updated = $this->events('EMPLOYEE_UPDATED');
        $this->assertCount(1, $updated, 'the no-op edit writes nothing');
        $this->assertSame(['first_name' => 'Maria', 'middle_name' => null], $updated[0]->before_metadata);
        $this->assertSame('Marie', $updated[0]->after_metadata['first_name']);
        $this->assertSame('TSC00001', $updated[0]->after_metadata['employee_code']);
        $this->assertSame($admin->id, $updated[0]->actor_user_id);
    }

    public function test_deactivate_and_reactivate_are_audited_and_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->json('id');

        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/activate")->assertOk()->assertJson(['active' => true]);
        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/activate")->assertOk();

        $this->assertCount(1, $this->events('EMPLOYEE_DEACTIVATED'));
        $this->assertCount(1, $this->events('EMPLOYEE_REACTIVATED'));
        $this->assertCount(1, $this->events('EMPLOYEE_CREATED'));
        $created = $this->events('EMPLOYEE_CREATED')[0];
        $this->assertSame('TSC00001', $created->after_metadata['employee_code']);
        $this->assertSame($id, $created->entity_id);
    }

    public function test_there_is_no_delete_route(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->json('id');

        $this->asUser($admin)->deleteJson("/api/v1/employees/{$id}")->assertStatus(405);
        $this->assertSame(1, Employee::count());
    }

    // ---------------------------------------------------------------- who may manage

    public function test_a_cashier_gets_403_on_every_management_operation(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::factory()->create(['store_id' => $admin->store_id]);
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);

        $this->asUser($cashier)->postJson('/api/v1/employees', $this->input())->assertStatus(403);
        $this->asUser($cashier)->getJson('/api/v1/employees')->assertStatus(403);
        $this->asUser($cashier)->getJson("/api/v1/employees/{$employee->id}")->assertStatus(403);
        $this->asUser($cashier)->patchJson("/api/v1/employees/{$employee->id}", ['first_name' => 'Hacked'])->assertStatus(403);
        $this->asUser($cashier)->postJson("/api/v1/employees/{$employee->id}/deactivate")->assertStatus(403);
        $this->asUser($cashier)->postJson("/api/v1/employees/{$employee->id}/activate")->assertStatus(403);
        $this->importCsv($cashier, "first_name,last_name,employee_type\nA,B,DIRECT\n")->assertStatus(403);
        $this->asUser($cashier)->getJson('/api/v1/employees/id-cards')->assertStatus(403);
        $this->asUser($cashier)->getJson('/api/v1/credit-policies')->assertStatus(403);
        $this->asUser($cashier)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '9999'])->assertStatus(403);

        $this->assertSame(1, Employee::count());
        $this->assertNotSame('Hacked', $employee->fresh()->first_name);
    }

    public function test_a_manager_registers_and_maintains_employees_and_the_audit_names_the_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);

        $id = $this->asUser($manager)->postJson('/api/v1/employees', $this->input(['first_name' => 'Test', 'last_name' => 'Hire']))
            ->assertStatus(201)->assertJson(['employee_code' => 'TSC00001', 'active' => true])->json('id');
        $this->asUser($manager)->getJson("/api/v1/employees/{$id}")->assertOk();
        $this->asUser($manager)->patchJson("/api/v1/employees/{$id}", ['first_name' => 'Renamed'])->assertOk()->assertJson(['first_name' => 'Renamed']);
        $this->asUser($manager)->postJson("/api/v1/employees/{$id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($manager)->postJson("/api/v1/employees/{$id}/activate")->assertOk()->assertJson(['active' => true]);
        $this->assertSame([$id], collect($this->asUser($manager)->getJson('/api/v1/employees?search=renamed')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame(['TSC00001'], collect($this->asUser($manager)->getJson('/api/v1/employees/id-cards')->assertOk()->json('data'))->pluck('employee_code')->all());
        $this->asUser($manager)->getJson('/api/v1/employees/lookup?code=TSC00001')->assertOk();

        foreach (['EMPLOYEE_CREATED', 'EMPLOYEE_UPDATED', 'EMPLOYEE_DEACTIVATED', 'EMPLOYEE_REACTIVATED'] as $type) {
            $events = $this->events($type);
            $this->assertCount(1, $events, $type);
            $this->assertSame($manager->id, $events[0]->actor_user_id, "$type is attributed to the manager");
        }
    }

    public function test_a_manager_can_read_the_credit_limits_but_cannot_change_them_or_import_a_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);

        $this->asUser($manager)->getJson('/api/v1/credit-policies')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($manager)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '9999'])->assertStatus(403);
        $this->importCsv($manager, "first_name,last_name,employee_type\nA,B,DIRECT\n")->assertStatus(403);
        $this->importCsv($manager, "first_name,last_name,employee_type\nA,B,DIRECT\n", dryRun: true)->assertStatus(403);

        $this->assertSame(0, Employee::count());
        $this->assertCount(0, $this->events('CREDIT_LIMIT_CHANGED'));
        $this->asUser($admin)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '1800'])->assertOk();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/employees')->assertStatus(401);
        $this->getJson('/api/v1/employees/lookup?code=TSC00001')->assertStatus(401);
    }

    public function test_the_capability_lists_carry_employee_manage_for_admin_and_manager_and_the_policy_one_for_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create();
        $login = fn (User $user) => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('capabilities');

        $this->assertContains('EMPLOYEE_MANAGE', $login($admin));
        $this->assertContains('EMPLOYEE_POLICY_MANAGE', $login($admin));
        $this->assertContains('EMPLOYEE_MANAGE', $login($manager));
        $this->assertNotContains('EMPLOYEE_POLICY_MANAGE', $login($manager));
        $this->assertNotContains('EMPLOYEE_MANAGE', $login($cashier));
        $this->assertNotContains('EMPLOYEE_POLICY_MANAGE', $login($cashier));
    }

    // ---------------------------------------------------------------- list, search, store scope

    public function test_the_list_searches_by_name_or_code_filters_and_pages_within_the_store(): void
    {
        $admin = User::factory()->admin()->create();
        $store = $admin->store_id;
        Employee::factory()->create(['store_id' => $store, 'first_name' => 'Ana', 'last_name' => 'Zamora', 'employee_code' => 'TSC00001']);
        Employee::factory()->create(['store_id' => $store, 'first_name' => 'Ben', 'last_name' => 'Aquino', 'employee_code' => 'TSC00002', 'active' => false]);
        Employee::factory()->subcontractor()->create(['store_id' => $store, 'first_name' => 'Cleo', 'last_name' => 'Aquino', 'employee_code' => 'SUB00001']);
        Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Elsewhere']);

        $all = $this->asUser($admin)->getJson('/api/v1/employees')->assertOk();
        $this->assertSame(['TSC00002', 'SUB00001', 'TSC00001'], collect($all->json('data'))->pluck('employee_code')->all(), 'ordered by last name, first name');
        $this->assertSame(3, $all->json('meta.total'));

        $this->assertSame(['TSC00001'], collect($this->asUser($admin)->getJson('/api/v1/employees?search=ana')->json('data'))->pluck('employee_code')->all());
        $this->assertSame(['TSC00002'], collect($this->asUser($admin)->getJson('/api/v1/employees?search=tsc00002')->json('data'))->pluck('employee_code')->all());
        $this->assertSame(['TSC00002', 'SUB00001'], collect($this->asUser($admin)->getJson('/api/v1/employees?search=aquino')->json('data'))->pluck('employee_code')->all());
        $this->assertSame(['TSC00002'], collect($this->asUser($admin)->getJson('/api/v1/employees?active=false')->json('data'))->pluck('employee_code')->all());
        $this->assertSame(['SUB00001'], collect($this->asUser($admin)->getJson('/api/v1/employees?employee_type=SUBCONTRACTOR')->json('data'))->pluck('employee_code')->all());
        $this->asUser($admin)->getJson('/api/v1/employees?employee_type=NOPE')->assertStatus(422);
        $this->assertCount(2, $this->asUser($admin)->getJson('/api/v1/employees?per_page=2')->json('data'));
        $this->assertCount(0, $this->asUser($admin)->getJson('/api/v1/employees?search=%25')->json('data'), 'a % is searched literally');
    }

    public function test_an_employee_of_another_store_is_not_found_for_every_operation(): void
    {
        $admin = User::factory()->admin()->create();
        $foreign = Employee::factory()->create();

        $this->asUser($admin)->getJson("/api/v1/employees/{$foreign->id}")->assertStatus(404)->assertJson(['error' => ['code' => 'EMPLOYEE_NOT_FOUND']]);
        $this->asUser($admin)->patchJson("/api/v1/employees/{$foreign->id}", ['first_name' => 'X'])->assertStatus(404);
        $this->asUser($admin)->postJson("/api/v1/employees/{$foreign->id}/deactivate")->assertStatus(404);
        $this->assertTrue($foreign->fresh()->active);
    }

    // ---------------------------------------------------------------- the till's lookup

    public function test_a_cashier_can_look_an_employee_up_by_exact_code_and_gets_only_what_the_till_needs(): void
    {
        $cashier = User::factory()->create();
        Employee::factory()->create(['store_id' => $cashier->store_id, 'first_name' => 'Ana', 'middle_name' => 'M', 'last_name' => 'Reyes', 'employee_code' => 'TSC00012']);

        $response = $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code=TSC00012')->assertOk();

        $this->assertEqualsCanonicalizing(['id', 'employee_code', 'employee_type', 'name', 'active', 'credit'], array_keys($response->json()));
        $response->assertJson(['credit' => ['limit' => '1500.00', 'used' => '0.00', 'remaining' => '1500.00']]);
        $response->assertJson(['employee_code' => 'TSC00012', 'employee_type' => 'DIRECT', 'name' => 'Ana M Reyes', 'active' => true]);
    }

    public function test_lookup_ignores_case_and_surrounding_whitespace_including_the_scanners_line_end(): void
    {
        $cashier = User::factory()->create();
        Employee::factory()->subcontractor()->create(['store_id' => $cashier->store_id, 'employee_code' => 'SUB00007']);

        foreach (['sub00007', '  SUB00007  ', "SUB00007\n", "SUB00007\r\n", "\tSuB00007 "] as $typed) {
            $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code='.rawurlencode($typed))->assertOk()->assertJson(['employee_code' => 'SUB00007']);
        }
    }

    public function test_lookup_is_exact_never_partial_and_never_a_listing(): void
    {
        $cashier = User::factory()->create();
        Employee::factory()->create(['store_id' => $cashier->store_id, 'first_name' => 'Ana', 'employee_code' => 'TSC00012']);
        Employee::factory()->create(['store_id' => $cashier->store_id, 'employee_code' => 'TSC00120']);

        foreach (['TSC0001', 'TSC000012', 'TSC', '00012', '12', 'TSC00012 TSC00120', 'Ana', 'TSC%', 'TSC00012%'] as $typed) {
            $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code='.rawurlencode($typed))
                ->assertStatus(404)->assertJson(['error' => ['code' => 'EMPLOYEE_NOT_FOUND']]);
        }
        $this->asUser($cashier)->getJson('/api/v1/employees/lookup')->assertStatus(422);
        $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code=')->assertStatus(422);
        $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code[]=TSC00012')->assertStatus(422);
        $this->asUser($cashier)->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_lookup_is_scoped_to_the_cashiers_store(): void
    {
        $cashier = User::factory()->create();
        Employee::factory()->create(['store_id' => Store::factory()->create()->id, 'employee_code' => 'TSC00012']);

        $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code=TSC00012')->assertStatus(404)->assertJson(['error' => ['code' => 'EMPLOYEE_NOT_FOUND']]);
    }

    public function test_lookup_refuses_a_deactivated_employee_with_a_clear_error_and_accepts_them_again_once_reactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $id = $this->asUser($admin)->postJson('/api/v1/employees', $this->input())->json('id');
        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/deactivate")->assertOk();

        $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code=TSC00001')
            ->assertStatus(409)->assertJson(['error' => ['code' => 'EMPLOYEE_INACTIVE']]);

        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/activate")->assertOk();
        $this->asUser($cashier)->getJson('/api/v1/employees/lookup?code=TSC00001')->assertOk();
    }

    // ---------------------------------------------------------------- credit limits

    public function test_a_store_starts_with_1500_for_direct_and_1000_for_subcontractor_and_the_admin_can_change_them(): void
    {
        $admin = User::factory()->admin()->create();

        $initial = $this->asUser($admin)->getJson('/api/v1/credit-policies')->assertOk();
        $this->assertSame([['DIRECT', '1500.00'], ['SUBCONTRACTOR', '1000.00']], collect($initial->json('data'))->map(fn ($p) => [$p['employee_type'], $p['limit_per_cutoff']])->all());

        $this->asUser($admin)->putJson('/api/v1/credit-policies/SUBCONTRACTOR', ['limit_per_cutoff' => '1250.5'])
            ->assertOk()->assertJson(['employee_type' => 'SUBCONTRACTOR', 'limit_per_cutoff' => '1250.50']);

        $after = $this->asUser($admin)->getJson('/api/v1/credit-policies');
        $this->assertSame(['1500.00', '1250.50'], collect($after->json('data'))->pluck('limit_per_cutoff')->all());

        $events = $this->events('CREDIT_LIMIT_CHANGED');
        $this->assertCount(1, $events);
        $this->assertSame(['limit_per_cutoff' => '1000.00'], $events[0]->before_metadata);
        $this->assertEquals(['limit_per_cutoff' => '1250.50', 'employee_type' => 'SUBCONTRACTOR'], $events[0]->after_metadata);
        $this->assertSame($admin->id, $events[0]->actor_user_id);
    }

    public function test_setting_the_limit_it_already_has_records_nothing_and_a_bad_value_or_type_is_refused(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '1500'])->assertOk()->assertJson(['limit_per_cutoff' => '1500.00']);
        $this->assertCount(0, $this->events('CREDIT_LIMIT_CHANGED'));

        foreach (['-1', 'abc', '10.999', '', '1,500'] as $bad) {
            $this->asUser($admin)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => $bad])->assertStatus(422);
        }
        $this->asUser($admin)->putJson('/api/v1/credit-policies/DIRECT', [])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/credit-policies/CONTRACTOR', ['limit_per_cutoff' => '10'])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '0'])->assertOk()->assertJson(['limit_per_cutoff' => '0.00']);
        $this->assertSame('1000.00', $this->asUser($admin)->getJson('/api/v1/credit-policies')->json('data.1.limit_per_cutoff'));
    }

    public function test_each_store_has_its_own_limits(): void
    {
        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();
        $this->asUser($adminA)->putJson('/api/v1/credit-policies/DIRECT', ['limit_per_cutoff' => '2000'])->assertOk();

        $this->assertSame('1500.00', $this->asUser($adminB)->getJson('/api/v1/credit-policies')->json('data.0.limit_per_cutoff'));
    }

    // ---------------------------------------------------------------- CSV import

    public function test_an_import_creates_employees_with_generated_codes_and_reports_them(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "first_name,middle_name,last_name,employee_type\nAna,Lopez,Reyes,DIRECT\nBen,,Cruz,tsc\nCarla,,Diaz,SUB\nDan,,Eve,subcontractor\n";

        $response = $this->importCsv($admin, $csv)->assertStatus(202);

        $this->assertSame(4, $response->json('created'));
        $this->assertSame([], $response->json('errors'));
        $this->assertSame(
            [[2, 'TSC00001', 'Ana Lopez Reyes'], [3, 'TSC00002', 'Ben Cruz'], [4, 'SUB00001', 'Carla Diaz'], [5, 'SUB00002', 'Dan Eve']],
            collect($response->json('employees'))->map(fn ($e) => [$e['row'], $e['employee_code'], $e['name']])->all(),
        );
        $this->assertSame(4, Employee::where('store_id', $admin->store_id)->count());

        $summary = $this->events('EMPLOYEES_IMPORTED');
        $this->assertCount(1, $summary);
        $this->assertSame(4, $summary[0]->after_metadata['created']);
        $this->assertSame(['TSC00001', 'TSC00002', 'SUB00001', 'SUB00002'], $summary[0]->after_metadata['codes']);
        $this->assertSame($summary[0]->entity_id, $this->events('EMPLOYEE_CREATED')[0]->after_metadata['batch_id']);
    }

    public function test_import_row_errors_are_reported_by_row_and_a_failed_row_burns_no_code(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "first_name,last_name,employee_type\nAna,Reyes,DIRECT\n,Cruz,DIRECT\nBen,,DIRECT\nCarla,Diaz,CONTRACTOR\nDan,Eve\nEli,Fox,SUB\n";

        $response = $this->importCsv($admin, $csv)->assertStatus(202);

        $this->assertSame(2, $response->json('created'));
        $this->assertSame(4, $response->json('failed'));
        $this->assertSame([3, 4, 5, 6], collect($response->json('errors'))->pluck('row')->all());
        $this->assertStringContainsString('first name', $response->json('errors.0.message'));
        $this->assertStringContainsString('last name', $response->json('errors.1.message'));
        $this->assertStringContainsString('CONTRACTOR', $response->json('errors.2.message'));
        $this->assertStringContainsString('Expected 3 values', $response->json('errors.3.message'));
        $this->assertSame(['TSC00001', 'SUB00001'], Employee::where('store_id', $admin->store_id)->orderBy('employee_code')->pluck('employee_code')->sortDesc()->values()->all());

        $next = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'After']));
        $this->assertSame('TSC00002', $next->json('employee_code'));
    }

    public function test_re_importing_a_file_skips_exact_duplicates_instead_of_enrolling_everyone_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Ana', 'last_name' => 'Reyes']))->assertStatus(201);
        $csv = "first_name,last_name,employee_type\nANA,reyes,DIRECT\nBen,Cruz,DIRECT\nben,CRUZ,tsc\nAna,Reyes,SUB\n";

        $first = $this->importCsv($admin, $csv)->assertStatus(202);
        $this->assertSame(2, $first->json('created'), 'Ben once, and Ana as a subcontractor (a different type)');
        $this->assertSame(2, $first->json('skipped'));
        $this->assertSame([2, 4], collect($first->json('skipped_rows'))->pluck('row')->all());

        $again = $this->importCsv($admin, $csv)->assertStatus(202);
        $this->assertSame(0, $again->json('created'));
        $this->assertSame(4, $again->json('skipped'));
        $this->assertSame(3, Employee::where('store_id', $admin->store_id)->count());
    }

    public function test_a_deactivated_employee_still_counts_as_a_duplicate(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->asUser($admin)->postJson('/api/v1/employees', $this->input(['first_name' => 'Ana', 'last_name' => 'Reyes']))->json('id');
        $this->asUser($admin)->postJson("/api/v1/employees/{$id}/deactivate")->assertOk();

        $this->assertSame(1, $this->importCsv($admin, "first_name,last_name,employee_type\nAna,Reyes,DIRECT\n")->json('skipped'));
    }

    public function test_a_dry_run_reports_the_codes_and_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "first_name,last_name,employee_type\nAna,Reyes,DIRECT\nBen,Cruz,SUB\n";

        $dry = $this->importCsv($admin, $csv, dryRun: true)->assertStatus(202);
        $this->assertSame(['TSC00001', 'SUB00001'], collect($dry->json('employees'))->pluck('employee_code')->all());
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, AuditEvent::where('event_type', 'LIKE', 'EMPLOYEE%')->count());
        $this->assertSame(0, (int) DB::table('employee_code_sequences')->sum('last_number'));

        $real = $this->importCsv($admin, $csv)->assertStatus(202);
        $this->assertSame(['TSC00001', 'SUB00001'], collect($real->json('employees'))->pluck('employee_code')->all());
    }

    public function test_a_file_that_cannot_be_read_is_refused_as_a_whole(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            '' => 'empty',
            "first_name,last_name\nA,B\n" => 'a missing employee_type column',
            "first_name,last_name,employee_type,employee_code\nA,B,SUB,SUB00099\n" => 'an employee_code column (codes are generated)',
            "first_name,first_name,last_name,employee_type\nA,A,B,SUB\n" => 'a repeated column',
            "first_name,last_name,employee_type\n\xC3\x28,B,SUB\n" => 'not UTF-8',
        ] as $csv => $why) {
            $response = $this->importCsv($admin, (string) $csv);
            $response->assertStatus(422);
            $this->assertArrayHasKey('file', $response->json('error.details'), $why);
        }
        $this->assertSame(0, Employee::count());
    }

    public function test_a_spreadsheet_formula_guard_apostrophe_is_removed_from_names_and_the_import_is_store_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->asUser($other)->postJson('/api/v1/employees', $this->input(['first_name' => 'Ana', 'last_name' => 'Reyes']))->assertStatus(201);

        $response = $this->importCsv($admin, "first_name,last_name,employee_type\n'-Ana,Reyes,DIRECT\nAna,Reyes,DIRECT\n")->assertStatus(202);

        $this->assertSame(2, $response->json('created'), 'another store\'s Ana is not a duplicate here');
        $this->assertSame('-Ana Reyes', $response->json('employees.0.name'));
        $this->assertSame(['TSC00001', 'TSC00002'], collect($response->json('employees'))->pluck('employee_code')->all());
    }

    // ---------------------------------------------------------------- ID cards

    public function test_the_id_card_data_lists_active_employees_with_name_type_and_code_and_can_be_narrowed(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Employee::factory()->create(['store_id' => $admin->store_id, 'first_name' => 'Ana', 'last_name' => 'Abad', 'employee_code' => 'TSC00001']);
        Employee::factory()->inactive()->create(['store_id' => $admin->store_id, 'last_name' => 'Gone', 'employee_code' => 'TSC00002']);
        $s = Employee::factory()->subcontractor()->create(['store_id' => $admin->store_id, 'first_name' => 'Sam', 'last_name' => 'Baker', 'employee_code' => 'SUB00001']);
        Employee::factory()->create(['last_name' => 'Elsewhere']);

        $all = $this->asUser($admin)->getJson('/api/v1/employees/id-cards')->assertOk();
        $this->assertSame(['TSC00001', 'SUB00001'], collect($all->json('data'))->pluck('employee_code')->all());
        $this->assertEqualsCanonicalizing(['id', 'employee_code', 'employee_type', 'name', 'active'], array_keys($all->json('data.0')));

        $this->assertSame(['SUB00001'], collect($this->asUser($admin)->getJson('/api/v1/employees/id-cards?employee_type=SUBCONTRACTOR')->json('data'))->pluck('employee_code')->all());
        $this->assertSame(['SUB00001'], collect($this->asUser($admin)->getJson("/api/v1/employees/id-cards?ids[]={$s->id}&ids[]=not-a-uuid")->json('data'))->pluck('employee_code')->all());
        $this->assertSame([], $this->asUser($admin)->getJson('/api/v1/employees/id-cards?ids[]=not-a-uuid')->json('data'));
        $this->assertSame($a->id, $this->asUser($admin)->getJson('/api/v1/employees/id-cards?search=ana')->json('data.0.id'));
    }
}

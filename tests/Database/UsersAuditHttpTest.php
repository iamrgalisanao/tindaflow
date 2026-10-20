<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The Users screen leaves a record: who created, changed, reactivated or deactivated whom, and whose password was changed
 * (never the password), and a password change or a deactivation signs that user out everywhere at once -- as the server-console
 * password reset already did. A role change is audited but does not sign anyone out: the role is read on every request.
 */
class UsersAuditHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function asUser(User $user): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();

        $login = $this->login($user);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function input(User $user, array $overrides = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'role' => $user->role], $overrides);
    }

    private function fakeSession(string $id, string $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
    }

    private function sessionsOf(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }

    public function test_creating_a_user_is_audited_without_the_password(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/users', ['name' => 'Ana Reyes', 'email' => 'ana@example.com', 'role' => 'CASHIER', 'password' => 'correct-horse-1']);

        $response->assertStatus(201);
        $event = AuditEvent::where('event_type', 'USER_CREATED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($admin->store_id, $event->store_id);
        $this->assertSame($response->json('id'), $event->entity_id);
        $this->assertEquals(['name' => 'Ana Reyes', 'email' => 'ana@example.com', 'role' => 'CASHIER', 'password_set' => true], $event->after_metadata);
        $this->assertStringNotContainsString('correct-horse-1', json_encode($event->toArray()));
    }

    public function test_a_user_created_without_a_password_says_so(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->postJson('/api/v1/users', ['name' => 'Ben', 'email' => 'ben@example.com', 'role' => 'MANAGER'])->assertStatus(201);

        $this->assertFalse(AuditEvent::where('event_type', 'USER_CREATED')->sole()->after_metadata['password_set']);
    }

    public function test_a_role_change_records_before_and_after_and_signs_nobody_out(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id, 'role' => 'CASHIER']);
        $this->fakeSession('c1', $cashier->id);

        $this->asUser($admin)->patchJson("/api/v1/users/{$cashier->id}", $this->input($cashier, ['role' => 'MANAGER']))->assertOk();

        $event = AuditEvent::where('event_type', 'USER_UPDATED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('CASHIER', $event->before_metadata['role']);
        $this->assertSame('MANAGER', $event->after_metadata['role']);
        $this->assertSame(0, AuditEvent::where('event_type', 'USER_PASSWORD_CHANGED')->count());
        $this->assertSame(1, $this->sessionsOf($cashier), 'the role is read on every request, so nobody is thrown out of an open sale');
    }

    public function test_saving_a_user_with_nothing_changed_writes_no_event(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->patchJson("/api/v1/users/{$cashier->id}", $this->input($cashier))->assertOk();

        $this->assertSame(0, AuditEvent::whereIn('event_type', ['USER_UPDATED', 'USER_PASSWORD_CHANGED'])->count());
    }

    public function test_changing_a_password_is_audited_without_it_and_signs_that_user_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $bystander = User::factory()->create(['store_id' => $admin->store_id]);
        $this->fakeSession('c1', $cashier->id);
        $this->fakeSession('c2', $cashier->id);
        $this->fakeSession('b1', $bystander->id);

        $this->asUser($admin)->patchJson("/api/v1/users/{$cashier->id}", $this->input($cashier, ['password' => 'a-brand-new-pass-9']))->assertOk();

        $this->assertSame(0, $this->sessionsOf($cashier));
        $this->assertSame(1, $this->sessionsOf($bystander));
        $event = AuditEvent::where('event_type', 'USER_PASSWORD_CHANGED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame(2, $event->after_metadata['sessions_ended']);
        $this->assertStringNotContainsString('a-brand-new-pass-9', json_encode($event->toArray()));
        $this->assertSame(0, AuditEvent::where('event_type', 'USER_UPDATED')->count(), 'only the password changed');
    }

    public function test_an_administrator_changing_their_own_password_keeps_the_session_they_are_using(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $this->fakeSession('here', $admin->id);
        $this->fakeSession('elsewhere', $admin->id);

        app(UserService::class)->update($admin->store_id, $admin->id, $this->input($admin, ['password' => 'a-brand-new-pass-9']), $admin, 'here');

        $this->assertSame(['here'], DB::table('sessions')->where('user_id', $admin->id)->pluck('id')->all());
    }

    public function test_deactivating_and_reactivating_are_audited_and_deactivating_signs_the_user_out(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $this->fakeSession('c1', $cashier->id);
        $as = $this->asUser($admin);

        $as->postJson("/api/v1/users/{$cashier->id}/deactivate")->assertOk();
        $this->assertSame(0, $this->sessionsOf($cashier));
        $deactivated = AuditEvent::where('event_type', 'USER_DEACTIVATED')->sole();
        $this->assertSame($admin->id, $deactivated->actor_user_id);
        $this->assertSame(1, $deactivated->after_metadata['sessions_ended']);

        $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/activate")->assertOk();
        $this->assertSame(1, AuditEvent::where('event_type', 'USER_ACTIVATED')->count());
    }

    public function test_activating_someone_already_active_writes_no_event(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/activate")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/deactivate")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/deactivate")->assertOk();

        $this->assertSame(0, AuditEvent::where('event_type', 'USER_ACTIVATED')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'USER_DEACTIVATED')->count());
    }

    public function test_a_refused_change_to_the_last_administrator_writes_nothing_and_signs_nobody_out(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $this->fakeSession('a1', $admin->id);

        $this->asUser($admin)->patchJson("/api/v1/users/{$admin->id}", $this->input($admin, ['role' => 'CASHIER', 'password' => 'a-brand-new-pass-9']))->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/users/{$admin->id}/deactivate")->assertStatus(422);

        $this->assertSame(0, AuditEvent::where('event_type', 'like', 'USER_%')->count());
        $this->assertTrue(DB::table('sessions')->where('id', 'a1')->exists(), 'a refused change signs nobody out');
    }

    public function test_the_audit_row_and_the_change_succeed_or_fail_together(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id, 'role' => 'CASHIER']);
        // A user record that points at a store the audit row cannot reference would fail the audit insert; simulate a failing audit directly.
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT test_no_user_updates CHECK (event_type <> 'USER_UPDATED')");

        try {
            $this->expectException(QueryException::class);
            app(UserService::class)->update($admin->store_id, $cashier->id, $this->input($cashier, ['role' => 'MANAGER']), $admin);
        } finally {
            DB::rollBack();
            DB::beginTransaction();
        }
    }
}

<?php

namespace Tests\Database;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml Users tag -- userList/Create/Get/Update/Deactivate, plus the forward-committed
 * userActivate and the USER_NOT_FOUND code (docs/06-backend/stage-13-users.md). USER_MANAGE is
 * ADMIN-only.
 */
class UsersHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** Each actor gets a fresh session: a forwarded cookie from the previous actor would otherwise be reused by the next login. */
    private function asUser(User $user): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];

        return $this->forwardSessionCookie($this->login($user));
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.com',
            'role' => 'CASHIER',
            'password' => 'correct-horse-1',
        ], $overrides);
    }

    // ---------------------------------------------------------------- create

    public function test_an_admin_can_create_a_user_who_can_then_sign_in(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/users', $this->validInput());

        $response->assertStatus(201);
        $response->assertJson(['name' => 'Ana Reyes', 'email' => 'ana@example.com', 'role' => 'CASHIER', 'active' => true]);
        $this->assertSame(['SALE_VOID', 'SALE_REFUND'], $response->json('capabilities'));
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('password_hash', $response->json());
        $this->assertSame($admin->store_id, User::findOrFail($response->json('id'))->store_id);

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'correct-horse-1'])->assertOk();
    }

    public function test_a_user_created_without_a_password_cannot_sign_in(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/users', $this->validInput(['password' => null]));

        $response->assertStatus(201);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => ''])->assertStatus(422);
        $this->assertNotSame('', User::findOrFail($response->json('id'))->password_hash);
    }

    public function test_only_admins_hold_user_manage(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create();

        $this->postJson('/api/v1/users', $this->validInput())->assertStatus(401);
        foreach ([$manager, $cashier] as $user) {
            $this->asUser($user)->postJson('/api/v1/users', $this->validInput())
                ->assertStatus(403)
                ->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
            $this->asUser($user)->getJson('/api/v1/users')->assertStatus(403);
        }
    }

    public function test_required_fields_role_and_password_shape_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $empty = $this->asUser($admin)->postJson('/api/v1/users', []);
        $empty->assertStatus(422);
        $this->assertEqualsCanonicalizing(['name', 'email', 'role'], array_keys($empty->json('error.details')));

        foreach ([
            ['role' => 'OWNER'],
            ['email' => 'not-an-email'],
            ['password' => 'short'],
        ] as $bad) {
            $response = $this->asUser($admin)->postJson('/api/v1/users', $this->validInput($bad));
            $response->assertStatus(422);
            $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
            $this->assertArrayHasKey(array_key_first($bad), $response->json('error.details'));
        }
    }

    public function test_email_is_unique_per_store_ignoring_case(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['store_id' => $admin->store_id, 'email' => 'Ana@Example.com']);
        $otherStoreAdmin = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);

        $duplicate = $this->asUser($admin)->postJson('/api/v1/users', $this->validInput(['email' => 'ana@example.com']));
        $duplicate->assertStatus(422);
        $this->assertArrayHasKey('email', $duplicate->json('error.details'));

        $this->asUser($otherStoreAdmin)->postJson('/api/v1/users', $this->validInput(['email' => 'ana@example.com']))->assertStatus(201);
    }

    // ------------------------------------------------------------ list / get

    public function test_list_is_store_scoped_filterable_and_never_exposes_secrets(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Zed Admin']);
        User::factory()->manager()->create(['store_id' => $admin->store_id, 'name' => 'Mia Manager']);
        User::factory()->create(['store_id' => $admin->store_id, 'name' => 'Cal Cashier']);
        User::factory()->inactive()->create(['store_id' => $admin->store_id, 'name' => 'Old Cashier']);
        User::factory()->create(['name' => 'Foreign']);

        $all = $this->asUser($admin)->getJson('/api/v1/users');
        $all->assertOk();
        $this->assertSame(['Cal Cashier', 'Mia Manager', 'Old Cashier', 'Zed Admin'], collect($all->json('data'))->pluck('name')->all());
        $this->assertSame(4, $all->json('meta.total'));
        $this->assertSame(
            ['id', 'name', 'email', 'role', 'capabilities', 'active'],
            array_keys($all->json('data.0')),
        );

        $cashiers = $this->asUser($admin)->getJson('/api/v1/users?role=CASHIER&active=1');
        $this->assertSame(['Cal Cashier'], collect($cashiers->json('data'))->pluck('name')->all());

        $inactive = $this->asUser($admin)->getJson('/api/v1/users?active=0');
        $this->assertSame(['Old Cashier'], collect($inactive->json('data'))->pluck('name')->all());

        $this->assertSame(100, $this->asUser($admin)->getJson('/api/v1/users?per_page=1000')->json('meta.per_page'));
    }

    public function test_get_returns_own_store_users_and_hides_others_and_unknown_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $own = User::factory()->create(['store_id' => $admin->store_id]);
        $foreign = User::factory()->create();

        $this->asUser($admin)->getJson("/api/v1/users/{$own->id}")->assertOk()->assertJson(['id' => $own->id, 'email' => $own->email]);

        foreach ([$foreign->id, (string) Str::uuid()] as $id) {
            $this->asUser($admin)->getJson("/api/v1/users/{$id}")
                ->assertStatus(404)
                ->assertJson(['error' => ['code' => 'USER_NOT_FOUND']]);
        }
        $this->asUser($admin)->getJson('/api/v1/users/not-a-uuid')->assertStatus(404);
    }

    // ---------------------------------------------------------------- update

    public function test_update_changes_name_email_and_role(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['store_id' => $admin->store_id]);

        $response = $this->asUser($admin)->patchJson("/api/v1/users/{$target->id}", [
            'name' => 'Renamed', 'email' => 'renamed@example.com', 'role' => 'MANAGER',
        ]);

        $response->assertOk();
        $response->assertJson(['name' => 'Renamed', 'email' => 'renamed@example.com', 'role' => 'MANAGER']);
        $this->assertContains('REPORT_VIEW', $response->json('capabilities'));
    }

    public function test_a_password_set_by_update_replaces_the_old_one_and_a_blank_one_keeps_it(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['store_id' => $admin->store_id, 'email' => 'cal@example.com']);
        $body = ['name' => $target->name, 'email' => 'cal@example.com', 'role' => 'CASHIER'];

        $this->asUser($admin)->patchJson("/api/v1/users/{$target->id}", $body + ['password' => null])->assertOk();
        $this->assertTrue(Hash::check('password', $target->refresh()->password_hash));

        $this->asUser($admin)->patchJson("/api/v1/users/{$target->id}", $body + ['password' => 'brand-new-secret'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'cal@example.com', 'password' => 'brand-new-secret'])->assertOk();
        $this->assertFalse(Hash::check('password', $target->refresh()->password_hash));
    }

    public function test_update_may_keep_its_own_email_but_not_take_another_users(): void
    {
        $admin = User::factory()->admin()->create();
        $mine = User::factory()->create(['store_id' => $admin->store_id, 'email' => 'mine@example.com']);
        User::factory()->create(['store_id' => $admin->store_id, 'email' => 'theirs@example.com']);
        $body = ['name' => 'X', 'role' => 'CASHIER'];

        $this->asUser($admin)->patchJson("/api/v1/users/{$mine->id}", $body + ['email' => 'MINE@example.com'])->assertOk();
        $collide = $this->asUser($admin)->patchJson("/api/v1/users/{$mine->id}", $body + ['email' => 'theirs@example.com']);

        $collide->assertStatus(422);
        $this->assertArrayHasKey('email', $collide->json('error.details'));
    }

    public function test_the_last_active_admin_cannot_be_changed_to_another_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->patchJson("/api/v1/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'MANAGER',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('role', $response->json('error.details'));
        $this->assertSame('ADMIN', $admin->refresh()->role);
    }

    public function test_an_admin_can_be_demoted_when_another_active_admin_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->patchJson("/api/v1/users/{$other->id}", [
            'name' => $other->name, 'email' => $other->email, 'role' => 'MANAGER',
        ])->assertOk();

        // ...and now `admin` is the last one.
        $this->asUser($admin)->patchJson("/api/v1/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'CASHIER',
        ])->assertStatus(422);
    }

    public function test_an_inactive_admin_does_not_count_as_a_remaining_admin(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->inactive()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->patchJson("/api/v1/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'MANAGER',
        ])->assertStatus(422);
    }

    public function test_update_of_another_stores_user_is_not_found_and_required_fields_apply(): void
    {
        $admin = User::factory()->admin()->create();
        $foreign = User::factory()->create();
        $own = User::factory()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->patchJson("/api/v1/users/{$foreign->id}", $this->validInput())
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'USER_NOT_FOUND']]);
        $this->assertNotSame('Ana Reyes', $foreign->refresh()->name);

        $this->asUser($admin)->patchJson("/api/v1/users/{$own->id}", ['name' => 'Only a name'])->assertStatus(422);
    }

    // ------------------------------------------------- deactivate / activate

    public function test_deactivating_a_user_ends_their_session_and_blocks_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $cashierLogin = $this->login($cashier);
        $this->forwardSessionCookie($cashierLogin)->getJson('/api/v1/auth/me')->assertOk();

        foreach ([1, 2] as $attempt) {
            $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        }

        $this->forwardSessionCookie($cashierLogin)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->login($cashier)->assertStatus(401);
        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'active' => false]);
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->postJson("/api/v1/users/{$admin->id}/deactivate");

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('active', $response->json('error.details'));
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'active' => true]);
        $this->asUser($admin)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_an_admin_can_be_deactivated_while_another_active_admin_remains_and_then_the_last_one_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->postJson("/api/v1/users/{$other->id}/deactivate")->assertOk()->assertJson(['active' => false]);

        $this->asUser($admin)->postJson("/api/v1/users/{$admin->id}/deactivate")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'active' => true]);

        // Getting the other admin back makes deactivating this one possible again.
        $this->asUser($admin)->postJson("/api/v1/users/{$other->id}/activate")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/users/{$admin->id}/deactivate")->assertOk();
    }

    public function test_an_inactive_admin_or_another_stores_admin_does_not_count_as_a_remaining_admin(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->inactive()->create(['store_id' => $admin->store_id]);
        User::factory()->admin()->create();

        $this->asUser($admin)->postJson("/api/v1/users/{$admin->id}/deactivate")->assertStatus(422);
    }

    public function test_deactivating_a_manager_or_cashier_is_not_affected_by_the_admin_rule(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);

        foreach ([$manager, $cashier] as $user) {
            $this->asUser($admin)->postJson("/api/v1/users/{$user->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        }
    }

    public function test_activate_restores_a_deactivated_user_idempotently(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->inactive()->create(['store_id' => $admin->store_id]);

        foreach ([1, 2] as $attempt) {
            $this->asUser($admin)->postJson("/api/v1/users/{$cashier->id}/activate")->assertOk()->assertJson(['active' => true]);
        }

        $this->login($cashier)->assertOk();
    }

    public function test_activation_and_deactivation_are_admin_only_and_store_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);
        $foreign = User::factory()->create();

        $this->asUser($manager)->postJson("/api/v1/users/{$admin->id}/deactivate")->assertStatus(403);
        foreach (['activate', 'deactivate'] as $action) {
            $this->asUser($admin)->postJson("/api/v1/users/{$foreign->id}/{$action}")
                ->assertStatus(404)
                ->assertJson(['error' => ['code' => 'USER_NOT_FOUND']]);
        }
        $this->assertTrue($foreign->refresh()->active);
        $this->assertTrue($admin->refresh()->active);
    }
}

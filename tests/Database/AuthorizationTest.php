<?php

namespace Tests\Database;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * A2 -- Authorization/Capability Foundation. Real HTTP tests through the
 * full auth + EnsureUserIsActive + `can:` middleware chain, against the
 * test-only /_test/requires-catalog-manage route (routes/web.php) --
 * placed under tests/Database/, not tests/Feature/, for the same reason
 * as AuthenticationSessionTest: every migration in this project is
 * PostgreSQL-specific and cannot run against phpunit.xml's default
 * sqlite connection, so a real `users` table is only available here.
 */
class AuthorizationTest extends PostgresSchemaTestCase
{
    private function createUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function login(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password']);
    }

    /** Simulates a new HTTP request from the same browser (see AuthenticationSessionTest for why this is necessary). */
    private function forwardCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    // 15. Whole-operation capability middleware works: an authorized role succeeds.
    public function test_a_user_with_the_capability_is_allowed_through(): void
    {
        $this->createUser(['email' => 'admin@test.local', 'role' => 'ADMIN']);
        $login = $this->login('admin@test.local');

        $response = $this->forwardCookie($login)->getJson('/api/v1/_test/requires-catalog-manage');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    // 11. Authenticated but lacking the capability -> 403 AUTHORIZATION_DENIED.
    public function test_a_user_without_the_capability_is_denied_with_403(): void
    {
        $this->createUser(['email' => 'cashier@test.local', 'role' => 'CASHIER']);
        $login = $this->login('cashier@test.local');

        $response = $this->forwardCookie($login)->getJson('/api/v1/_test/requires-catalog-manage');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->assertIsString($response->json('error.request_id'));
    }

    // 12. Unauthenticated -> 401 AUTHENTICATION_REQUIRED, never 403.
    public function test_an_unauthenticated_request_is_rejected_with_401_not_403(): void
    {
        $response = $this->getJson('/api/v1/_test/requires-catalog-manage');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 13. Inactive-user precedence: EnsureUserIsActive must reject at 401
    // before the Gate ever runs, even for a role that WOULD otherwise
    // have the capability.
    public function test_an_inactive_user_is_rejected_with_401_before_reaching_the_gate(): void
    {
        $user = $this->createUser(['email' => 'admin@test.local', 'role' => 'ADMIN']);
        $login = $this->login('admin@test.local');

        $user->update(['active' => false]);

        $response = $this->forwardCookie($login)->getJson('/api/v1/_test/requires-catalog-manage');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 16. Conditional capabilities are not automatically imposed on any
    // whole endpoint -- PRICE_OVERRIDE/DISCOUNT_OVERRIDE/CASH_OUT never
    // appear as an x-capability anywhere in openapi.yaml (see
    // CapabilityOpenApiTraceabilityTest), and no route in this
    // application applies them as a route-level `can:` gate.
    public function test_no_route_gates_on_a_conditional_capability(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->middleware())->flatten();

        foreach (['PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'CASH_OUT'] as $conditional) {
            $this->assertFalse($routes->contains("can:$conditional"), "$conditional must not be a route-level whole-operation gate");
        }
    }
}

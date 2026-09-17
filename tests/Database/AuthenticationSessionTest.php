<?php

namespace Tests\Database;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * A1 -- Human Authentication / Session Foundation. Real HTTP feature-style
 * tests (through the full kernel/middleware stack: routing, session,
 * CSRF, auth guard), but placed under tests/Database/ rather than
 * tests/Feature/ -- deliberately, not by oversight. Every migration in
 * this project is PostgreSQL-specific (partial unique indexes, raw CHECK
 * constraints) and cannot run against phpunit.xml's default sqlite
 * connection (confirmed directly: `ALTER TABLE ... ADD CONSTRAINT` fails
 * under sqlite), so a `users` table with real rows to authenticate
 * against is only available via PostgresSchemaTestCase, exactly the same
 * reason every other schema-dependent test in this project already lives
 * under tests/Database/ instead of tests/Feature/.
 *
 * A note on testing session continuity: Laravel's JSON test helpers
 * (postJson/getJson) do not forward cookies between calls unless
 * withCredentials() is used together with an explicit withCookie(), and
 * the auth guard caches its resolved user for the lifetime of the
 * (shared, per-test) application instance. forwardCookie() below
 * reproduces a genuinely new "request" the way a real browser would --
 * forgetting the cached guard and presenting only the session cookie
 * carried over from the prior response -- so these tests exercise real
 * session-cookie-based continuity, not the guard's in-memory cache.
 */
class AuthenticationSessionTest extends PostgresSchemaTestCase
{
    private function createUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    /** Simulates a new HTTP request from the same browser: forwards the real session cookie, discards any cached guard resolution. */
    private function forwardCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    // 1. Successful login.
    public function test_successful_login_returns_the_user_summary(): void
    {
        $user = $this->createUser(['email' => 'cashier@test.local', 'role' => 'CASHIER']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@test.local',
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => 'cashier@test.local',
            'role' => 'CASHIER',
            'capabilities' => ['SALE_VOID', 'SALE_REFUND'],
            'active' => true,
        ]);
    }

    // 2. Wrong password.
    public function test_wrong_password_is_rejected(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@test.local',
            'password' => 'not-the-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 3. Unknown email -- identical external outcome to a wrong password.
    public function test_unknown_email_is_rejected_identically_to_a_wrong_password(): void
    {
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@test.local',
            'password' => 'whatever',
        ]);

        $this->createUser(['email' => 'known@test.local']);
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@test.local',
            'password' => 'wrong',
        ]);

        $unknownEmail->assertStatus(401);
        $wrongPassword->assertStatus(401);

        // request_id is a per-request correlation ID and is expected to
        // differ; everything else must be externally indistinguishable.
        $stripRequestId = fn (array $body) => tap($body, function (&$b) {
            unset($b['error']['request_id']);
        });
        $this->assertSame($stripRequestId($unknownEmail->json()), $stripRequestId($wrongPassword->json()));
    }

    // 4. Inactive user cannot login, and the failure does not reveal the credentials were otherwise correct.
    public function test_inactive_user_cannot_login(): void
    {
        $this->createUser(['email' => 'inactive@test.local', 'active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@test.local',
            'password' => 'password',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
        $this->assertGuest('web');
    }

    // 5. Session fixation: a successful login always issues a fresh session ID.
    public function test_successful_login_regenerates_the_session_identifier(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);

        $anonymous = $this->getJson('/');
        $preLoginCookie = collect($anonymous->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $login = $this->withUnencryptedCookie(config('session.cookie'), $preLoginCookie?->getValue() ?? '')
            ->withCredentials()
            ->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $postLoginCookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $login->assertOk();
        $this->assertNotNull($preLoginCookie);
        $this->assertNotNull($postLoginCookie);
        $this->assertNotSame($preLoginCookie->getValue(), $postLoginCookie->getValue(), 'the session cookie must change across a successful login');
    }

    // 6 & 7. /auth/me: authenticated success, and unauthenticated rejection.
    public function test_me_returns_the_current_authenticated_user(): void
    {
        $user = $this->createUser(['email' => 'cashier@test.local', 'role' => 'MANAGER']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $me = $this->forwardCookie($login)->getJson('/api/v1/auth/me');

        $me->assertOk();
        $me->assertJson(['id' => $user->id, 'email' => 'cashier@test.local', 'role' => 'MANAGER']);
    }

    public function test_me_without_a_session_is_rejected(): void
    {
        // No login anywhere in this test method -- a genuinely fresh,
        // unauthenticated application instance.
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 8 & 9. Logout destroys the current session; the next request is rejected.
    public function test_logout_destroys_the_session_and_the_next_request_is_rejected(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $logout = $this->forwardCookie($login)->postJson('/api/v1/auth/logout');
        $logout->assertStatus(204);

        $meAfterLogout = $this->forwardCookie($login)->getJson('/api/v1/auth/me');
        $meAfterLogout->assertStatus(401);
        $meAfterLogout->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 10 & 11. An existing session becomes inactive: the very next protected
    // request is rejected, AND the session is genuinely invalidated -- not
    // merely re-checked live (reactivating the user must not resurrect it).
    public function test_session_is_invalidated_the_moment_the_user_becomes_inactive(): void
    {
        $user = $this->createUser(['email' => 'cashier@test.local']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $user->update(['active' => false]);

        $whileInactive = $this->forwardCookie($login)->getJson('/api/v1/auth/me');
        $whileInactive->assertStatus(401);
        $whileInactive->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);

        // Reactivate, but present the SAME (now-old) session cookie again --
        // if the session were merely re-checked rather than invalidated,
        // this would succeed. It must not.
        $user->update(['active' => true]);
        $afterReactivation = $this->forwardCookie($login)->getJson('/api/v1/auth/me');
        $afterReactivation->assertStatus(401);
    }

    // 12. Login throttling reaches 429 RATE_LIMITED.
    public function test_login_throttling_returns_429_rate_limited(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);
        $maxAttempts = (int) config('tindaflow.login_throttle.max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $throttled = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'wrong']);
        $throttled->assertStatus(429);
        $throttled->assertJson(['error' => ['code' => 'RATE_LIMITED']]);
    }

    // 13. Throttling applies identically whether or not the email exists.
    public function test_login_throttling_applies_identically_to_unknown_emails(): void
    {
        $maxAttempts = (int) config('tindaflow.login_throttle.max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ghost@test.local', 'password' => 'whatever'])
                ->assertStatus(401);
        }

        $throttled = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@test.local', 'password' => 'whatever']);
        $throttled->assertStatus(429);
        $throttled->assertJson(['error' => ['code' => 'RATE_LIMITED']]);
    }

    // 14. CSRF rejects a state-changing request. Forced on: APP_ENV=testing
    // makes Laravel's CSRF middleware bypass itself automatically
    // (PreventRequestForgery::runningUnitTests()), so this test overrides
    // the container's 'env' binding to exercise real enforcement.
    public function test_csrf_is_enforced_on_a_state_changing_request(): void
    {
        $this->app->instance('env', 'production');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'whoever@test.local',
            'password' => 'whatever',
        ]);

        $response->assertStatus(419);
    }

    // 15 & 16. UserSummary's exact field shape; no sensitive fields leaked.
    public function test_user_summary_has_exactly_the_frozen_field_shape(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $response->assertOk();
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['active', 'capabilities', 'email', 'id', 'name', 'role'], $keys);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('password_hash', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());
    }

    // 17. Multiple simultaneous sessions are allowed by default.
    public function test_multiple_simultaneous_sessions_are_allowed(): void
    {
        $this->createUser(['email' => 'cashier@test.local']);

        $loginA = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);
        $loginB = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);

        $this->forwardCookie($loginA)->getJson('/api/v1/auth/me')->assertOk();
        $this->forwardCookie($loginB)->getJson('/api/v1/auth/me')->assertOk();

        // Using session B must not have invalidated session A.
        $this->forwardCookie($loginA)->getJson('/api/v1/auth/me')->assertOk();
    }

    // 18. Session cookie settings match the frozen security intent.
    public function test_session_cookie_configuration_matches_the_frozen_security_intent(): void
    {
        $this->assertSame('tindaflow_session', config('session.cookie'));
        $this->assertSame('strict', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
        // config/session.php's own default is 'database' (env('SESSION_DRIVER',
        // 'database')); phpunit.xml intentionally overrides it to 'array' for
        // the fast Unit/Feature suites, so the runtime value here is not
        // asserted -- see config/session.php directly for the code-level default.

        $this->createUser(['email' => 'cashier@test.local']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'cashier@test.local', 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_session');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
    }
}

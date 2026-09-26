<?php

namespace Tests\Database;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * What a till sees when its session has expired (SESSION_LIFETIME, database driver): the browser's cookies are gone or the
 * server no longer has the session row. Real CSRF enforcement is switched on (APP_ENV=testing bypasses it), because CSRF is
 * checked BEFORE authentication and is what an expired session actually fails first. Module A Decision Register section 13:
 * every expired or absent session converges on 401 AUTHENTICATION_REQUIRED; no SESSION_EXPIRED code exists.
 */
class SessionExpiryHttpTest extends PostgresSchemaTestCase
{
    /** A signed-in browser: real session and XSRF cookies, obtained the way a browser gets them. */
    private function signedInBrowser(User $user): array
    {
        $this->app->instance('env', 'production');

        $bootstrap = $this->getJson('/');
        $xsrf = collect($bootstrap->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        $session = collect($bootstrap->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $login = $this->withUnencryptedCookie(config('session.cookie'), $session->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $xsrf->getValue())
            ->withHeader('X-XSRF-TOKEN', urldecode($xsrf->getValue()))
            ->withCredentials()
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertOk();

        // After login the session id and CSRF token rotate: the cookies to keep are the ones the LOGIN response set.
        $sessionAfter = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $xsrfAfter = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');

        return ['session' => $sessionAfter->getValue(), 'xsrf' => $xsrfAfter->getValue()];
    }

    /** @param  array{session: string|null, xsrf: string|null}  $cookies */
    private function postAs(string $uri, array $cookies): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $request = $this->withCredentials();
        if ($cookies['session'] !== null) {
            $request = $request->withUnencryptedCookie(config('session.cookie'), $cookies['session']);
        }
        if ($cookies['xsrf'] !== null) {
            $request = $request->withUnencryptedCookie('XSRF-TOKEN', $cookies['xsrf'])->withHeader('X-XSRF-TOKEN', urldecode($cookies['xsrf']));
        }

        return $request->postJson($uri, []);
    }

    private function assertAuthenticationRequired(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
        $this->assertIsString($response->json('error.request_id'));
        $this->assertSame(['error'], array_keys($response->json()), 'the body is the error envelope and nothing else');
    }

    public function test_a_write_from_a_session_the_server_no_longer_has_is_authentication_required(): void
    {
        $this->app->instance('env', 'production');

        // The browser still sends its cookies, but the server has swept the session they name (any id it does not know).
        $this->assertAuthenticationRequired($this->postAs('/api/v1/sales', ['session' => Str::random(40), 'xsrf' => Str::random(40)]));
    }

    public function test_a_write_from_a_browser_whose_cookies_have_expired_is_authentication_required(): void
    {
        $this->app->instance('env', 'production');

        $this->assertAuthenticationRequired($this->postAs('/api/v1/sales', ['session' => null, 'xsrf' => null]));
    }

    public function test_the_same_holds_for_other_writes_a_till_makes(): void
    {
        $this->app->instance('env', 'production');

        foreach (['/api/v1/shifts/open', '/api/v1/sales/00000000-0000-7000-8000-000000000000/void', '/api/v1/inventory/adjustments', '/api/v1/shifts/00000000-0000-7000-8000-000000000000/close'] as $uri) {
            $this->assertAuthenticationRequired($this->postAs($uri, ['session' => null, 'xsrf' => null]));
        }
    }

    public function test_login_without_a_csrf_token_is_still_a_419(): void
    {
        $this->app->instance('env', 'production');

        $this->postJson('/api/v1/auth/login', ['email' => 'whoever@test.local', 'password' => 'whatever'])->assertStatus(419);
    }

    public function test_a_live_session_with_a_wrong_csrf_token_is_still_a_419_not_a_401(): void
    {
        $user = User::factory()->create();
        $cookies = $this->signedInBrowser($user);
        $this->app['auth']->forgetGuards();

        $response = $this->withCredentials()
            ->withUnencryptedCookie(config('session.cookie'), $cookies['session'])
            ->withUnencryptedCookie('XSRF-TOKEN', $cookies['xsrf'])
            ->withHeader('X-XSRF-TOKEN', 'not-the-token')
            ->postJson('/api/v1/sales', []);

        $response->assertStatus(419);
    }
}

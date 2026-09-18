<?php

namespace Tests\Database;

use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A5 -- module-a-auth-terminal-initialization.md §13's required test
 * matrix (18 items), run end to end against A0-A4 before touching Stage
 * 6C's controller (A6) at all. Most of the 18 items are already proven
 * by A1/A2/A3/A4's own test files (AuthenticationSessionTest,
 * AuthorizationTest, TerminalEnrollmentTest) -- see §21 for the full
 * item-by-item citation. This file exists only for the items that had
 * no single existing test proving them, or that benefit from a
 * cross-cutting proof spanning every registered route rather than one
 * route at a time.
 */
class ModuleAHttpIntegrationTest extends PostgresSchemaTestCase
{
    private function admin(array $overrides = []): User
    {
        return User::factory()->admin()->create($overrides);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    /** §13 item 7: every Module A route except authLogin itself must reject an unauthenticated request. */
    public function test_every_protected_module_a_route_rejects_an_unauthenticated_request(): void
    {
        $terminalId = (string) Str::uuid();

        $protectedRoutes = [
            ['POST', '/api/v1/auth/logout'],
            ['GET', '/api/v1/auth/me'],
            ['POST', '/api/v1/terminal-enrollment-tokens'],
            ['POST', '/api/v1/terminal/enroll'],
            ['GET', '/api/v1/terminal/current'],
            ['GET', '/api/v1/terminals'],
            ['GET', "/api/v1/terminals/{$terminalId}"],
            ['POST', "/api/v1/terminals/{$terminalId}/revoke"],
        ];

        foreach ($protectedRoutes as [$method, $uri]) {
            $response = $this->json($method, $uri);

            $response->assertStatus(401, "expected {$method} {$uri} to reject an unauthenticated request with 401, got {$response->getStatusCode()}");
            $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
        }
    }

    /** §13 item 7 gap: logout itself had no dedicated no-session test. */
    public function test_logout_without_a_session_returns_authentication_required(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    /** Simulates a new HTTP request from the same browser (see AuthenticationSessionTest for why this is necessary). */
    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function withTerminalCredential(TestResponse $enrollResponse): static
    {
        $cookie = collect($enrollResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $this->withUnencryptedCookie('tindaflow_terminal', $cookie?->getValue() ?? '')->withCredentials();
    }

    /**
     * §13 items 14/15 on the one currently-live POS_TERMINAL operation:
     * terminalCurrent derives its Terminal exclusively from the
     * tindaflow_terminal credential and its User exclusively from the
     * session -- never from request input. A spoofed terminal_id/
     * user_id/cashier_id in the query string must be silently ignored,
     * not merely absent from the FormRequest's validated() output (this
     * route has no FormRequest at all to strip it).
     */
    public function test_terminal_current_ignores_spoofed_request_input(): void
    {
        $admin = $this->admin();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $tokenResponse = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        $spoofedOtherTerminal = Terminal::factory()->create();
        $spoofedOtherUser = User::factory()->admin()->create();

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($enroll)->getJson(
            '/api/v1/terminal/current?terminal_id='.$spoofedOtherTerminal->id.'&user_id='.$spoofedOtherUser->id.'&cashier_id='.$spoofedOtherUser->id
        );

        $response->assertOk();
        $response->assertJson(['id' => $terminal->id]);
        $this->assertNotSame($spoofedOtherTerminal->id, $response->json('id'));
    }
}

<?php

namespace Tests\Database;

use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A3 -- Terminal Enrollment / Credential Verification (ADR-011). Real
 * HTTP tests through the full middleware chain, under tests/Database/
 * for the same reason as AuthenticationSessionTest/AuthorizationTest:
 * every migration in this project is PostgreSQL-specific.
 *
 * Every authenticated call explicitly forwards the login response's
 * session cookie via asAdmin()/forwardSessionCookie() -- the auth
 * guard's in-memory caching (see AuthenticationSessionTest's docblock)
 * makes it easy to accidentally rely on a PRIOR call's ambient
 * authenticated state instead of a genuinely forwarded cookie, which
 * would prove nothing about real session continuity.
 */
class TerminalEnrollmentTest extends PostgresSchemaTestCase
{
    private function admin(array $overrides = []): User
    {
        return User::factory()->admin()->create($overrides);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
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

    // 1. Authorized administrator can create an enrollment token.
    public function test_authorized_administrator_can_create_an_enrollment_token(): void
    {
        $admin = $this->admin();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);

        $response->assertStatus(201);
        $response->assertJson(['terminal_id' => $terminal->id]);
        $this->assertIsString($response->json('token'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertIsString($response->json('expires_at'));
    }

    // 2. Unauthorized role -> 403 AUTHORIZATION_DENIED.
    public function test_a_role_without_terminal_manage_is_denied(): void
    {
        $cashier = User::factory()->create(['role' => 'CASHIER']);
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $cashier->store_id]);
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    // 3. Unauthenticated -> 401.
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => (string) Str::uuid()]);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // 4, 5, 6. Plaintext returned once, never persisted; hash persisted.
    public function test_token_plaintext_is_returned_once_and_only_the_hash_is_persisted(): void
    {
        [, , $plaintext] = $this->issueToken();
        $row = TerminalEnrollmentToken::first();

        $this->assertNotSame($plaintext, $row->token_hash);
        $this->assertSame(hash('sha256', $plaintext), $row->token_hash);
        $this->assertStringNotContainsString($plaintext, json_encode($row->getAttributes()));
    }

    // 7. Expired token rejected.
    public function test_expired_token_is_rejected(): void
    {
        [$admin, , $plaintext, $login] = $this->issueToken();
        TerminalEnrollmentToken::first()->update(['expires_at' => now()->subMinute()]);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'ENROLLMENT_TOKEN_INVALID']]);
    }

    // 8. Unknown token rejected.
    public function test_unknown_token_is_rejected(): void
    {
        $admin = $this->admin();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => 'this-token-was-never-issued']);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'ENROLLMENT_TOKEN_INVALID']]);
    }

    // 9 & 20. Reused token rejected; re-enrollment requires a fresh token.
    public function test_a_used_token_cannot_be_reused(): void
    {
        [, , $plaintext, $login] = $this->issueToken();

        $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext])->assertOk();

        $second = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);
        $second->assertStatus(409);
        $second->assertJson(['error' => ['code' => 'ENROLLMENT_TOKEN_INVALID']]);
    }

    // 11, 12, 13, 14. Enrollment issues the terminal cookie; raw credential
    // never persisted; hash persisted; matches the deterministic resolver.
    //
    // Note: the cookie's raw getValue() is Laravel's own EncryptCookies
    // ciphertext, not the plaintext credential -- decrypting it here to
    // hash-compare it directly would just reimplement DecryptCookies. A
    // real round-trip request (GET /terminal/current, exactly how a
    // browser would present it) is the correct way to prove the cookie
    // resolves to this terminal and is the ONLY thing every other
    // credential-behavior test in this file already does.
    public function test_successful_enrollment_issues_a_terminal_cookie_and_it_resolves_this_terminal(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $response->assertOk();
        $response->assertJson(['id' => $terminal->id, 'terminal_code' => $terminal->terminal_code]);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');
        $this->assertNotNull($cookie, 'a successful enrollment must set the tindaflow_terminal cookie');

        $terminal->refresh();
        $this->assertNotNull($terminal->credential_hash);
        $this->assertNotSame($plaintext, $terminal->credential_hash, 'the raw enrollment token must never be stored as the terminal credential hash');

        $current = $this->forwardSessionCookie($login)->withTerminalCredential($response)->getJson('/api/v1/terminal/current');
        $current->assertOk();
        $current->assertJson(['id' => $terminal->id]);
    }

    // 15, 16, 17. Terminal resolves correctly; missing/unknown credential -> TERMINAL_NOT_ENROLLED.
    public function test_terminal_current_resolves_the_enrolled_terminal(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        $response->assertOk();
        $response->assertJson(['id' => $terminal->id]);
    }

    public function test_terminal_current_without_any_credential_returns_terminal_not_enrolled(): void
    {
        $admin = $this->admin();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/terminal/current');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    public function test_terminal_current_with_an_unknown_credential_returns_terminal_not_enrolled(): void
    {
        $admin = $this->admin();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->withUnencryptedCookie('tindaflow_terminal', 'not-a-real-credential')
            ->withCredentials()
            ->getJson('/api/v1/terminal/current');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    // 18, 19. Revoked credential -> TERMINAL_REVOKED; revocation leaves the human session valid.
    public function test_revoked_credential_is_rejected_and_the_human_session_stays_valid(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$terminal->id}/revoke")->assertOk();

        $current = $this->forwardSessionCookie($login)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');
        $current->assertStatus(403);
        $current->assertJson(['error' => ['code' => 'TERMINAL_REVOKED']]);

        // The human session itself is untouched by revoking a terminal.
        $me = $this->forwardSessionCookie($login)->getJson('/api/v1/auth/me');
        $me->assertOk();
    }

    // 21. Old credential stops working after re-enrollment; re-enrollment clears revocation.
    public function test_re_enrollment_issues_a_new_credential_and_invalidates_the_old_one(): void
    {
        [$admin, $terminal, $firstToken, $login] = $this->issueToken();
        $firstEnroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $firstToken]);
        $firstCredential = collect($firstEnroll->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal')->getValue();

        $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$terminal->id}/revoke")->assertOk();

        $secondTokenResponse = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $secondToken = $secondTokenResponse->json('token');

        $secondEnroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $secondToken]);
        $secondEnroll->assertOk();
        $secondCredential = collect($secondEnroll->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal')->getValue();

        $this->assertNotSame($firstCredential, $secondCredential);

        // Old credential: unknown now (the column was overwritten, not appended).
        $oldStillWorks = $this->forwardSessionCookie($login)
            ->withUnencryptedCookie('tindaflow_terminal', $firstCredential)->withCredentials()
            ->getJson('/api/v1/terminal/current');
        $oldStillWorks->assertStatus(403);
        $oldStillWorks->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);

        // New credential: works, and the terminal is no longer revoked.
        $newWorks = $this->forwardSessionCookie($login)
            ->withUnencryptedCookie('tindaflow_terminal', $secondCredential)->withCredentials()
            ->getJson('/api/v1/terminal/current');
        $newWorks->assertOk();
        $this->assertNull($terminal->refresh()->revoked_at);
    }

    // 22. The partial unique credential_hash index remains a real, effective invariant.
    public function test_duplicate_credential_hash_is_rejected_by_the_database(): void
    {
        $store = Store::factory()->create();
        $sameHash = hash('sha256', 'colliding-secret');
        Terminal::factory()->create(['store_id' => $store->id, 'credential_hash' => $sameHash]);

        $this->expectException(QueryException::class);

        Terminal::factory()->create(['store_id' => $store->id, 'credential_hash' => $sameHash]);
    }

    // 23. Authoritative IDs cannot come from the request body.
    public function test_enrollment_ignores_any_extra_body_fields(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $otherTerminal = Terminal::factory()->create(['store_id' => $admin->store_id]);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', [
            'token' => $plaintext,
            'terminal_id' => $otherTerminal->id, // must be ignored -- the token alone determines the terminal
        ]);

        $response->assertOk();
        $response->assertJson(['id' => $terminal->id]);
    }

    // 24. Regression: terminal 403 codes are not swallowed/relabeled as AUTHORIZATION_DENIED.
    public function test_terminal_403_codes_are_not_converted_into_authorization_denied(): void
    {
        $admin = $this->admin();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/terminal/current');

        $response->assertStatus(403);
        $this->assertSame('TERMINAL_NOT_ENROLLED', $response->json('error.code'));
        $this->assertNotSame('AUTHORIZATION_DENIED', $response->json('error.code'));
    }

    // 25. No terminal credential required for allowed BACK_OFFICE Terminal-management operations.
    public function test_management_operations_do_not_require_a_terminal_credential(): void
    {
        $admin = $this->admin();
        $terminal = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        // No tindaflow_terminal cookie presented anywhere here.
        $this->forwardSessionCookie($login)->getJson('/api/v1/terminals')->assertOk();
        $this->forwardSessionCookie($login)->getJson("/api/v1/terminals/{$terminal->id}")->assertOk();
    }

    // 26. No A4 Store-coherence rule has been silently implemented.
    public function test_terminal_resolution_does_not_enforce_user_store_coherence(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        // A different store's admin, logging in from the SAME (enrolled) browser.
        $otherStoreAdmin = User::factory()->admin()->create();
        $this->assertNotSame($admin->store_id, $otherStoreAdmin->store_id);
        $otherLogin = $this->login($otherStoreAdmin);

        $response = $this->forwardSessionCookie($otherLogin)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        // A3 does not compare user.store_id to terminal.store_id -- that's A4.
        $response->assertOk();
        $response->assertJson(['id' => $terminal->id]);
    }

    // Store scoping for terminal management: another store's terminal is 404, not exposed.
    public function test_terminal_management_is_scoped_to_the_actors_own_store(): void
    {
        $admin = $this->admin();
        $otherStoreTerminal = Terminal::factory()->create(); // different store, per factory default
        $login = $this->login($admin);

        $get = $this->forwardSessionCookie($login)->getJson("/api/v1/terminals/{$otherStoreTerminal->id}");
        $get->assertStatus(404);

        $createToken = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $otherStoreTerminal->id]);
        $createToken->assertStatus(404);

        $revoke = $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$otherStoreTerminal->id}/revoke");
        $revoke->assertStatus(404);
    }

    // Cookie configuration.
    public function test_terminal_cookie_configuration_matches_the_frozen_security_intent(): void
    {
        [, , $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $cookie = collect($enroll->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
    }

    // Human logout does not touch the terminal credential; secrets never leak into the error envelope.
    public function test_human_logout_does_not_delete_the_terminal_credential(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $this->forwardSessionCookie($login)->postJson('/api/v1/auth/logout')->assertStatus(204);

        // The terminal credential itself is still valid -- logout only tore
        // down the human session, so a fresh login (from the same browser,
        // which still carries the terminal cookie) must still resolve it.
        $freshLogin = $this->login($admin);
        $current = $this->forwardSessionCookie($freshLogin)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        $current->assertOk();
        $current->assertJson(['id' => $terminal->id]);
    }

    public function test_error_responses_never_contain_the_plaintext_secret(): void
    {
        [, , $plaintext, $login] = $this->issueToken();
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext])->assertOk();

        $reused = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);
        $this->assertStringNotContainsString($plaintext, $reused->getContent());
    }

    /** @return array{0: User, 1: Terminal, 2: string, 3: TestResponse} */
    private function issueToken(): array
    {
        $admin = $this->admin();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);

        return [$admin, $terminal, $response->json('token'), $login];
    }
}

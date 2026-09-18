<?php

namespace Tests\Database;

use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
use App\Models\User;
use App\Services\Terminal\TerminalEnrollmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    // A4: ComposeAuthoritativeContext now enforces user.store_id ==
    // terminal.store_id. A different store's admin, authenticated from
    // the SAME (enrolled) browser, must not be able to resolve this
    // terminal -- reported as TERMINAL_NOT_ENROLLED (403), the same
    // outward signal as no credential at all, never a distinct code and
    // never the terminal's real data.
    public function test_terminal_resolution_now_enforces_user_store_coherence(): void
    {
        [$admin, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        // A different store's admin, logging in from the SAME (enrolled) browser.
        $otherStoreAdmin = User::factory()->admin()->create();
        $this->assertNotSame($admin->store_id, $otherStoreAdmin->store_id);
        $otherLogin = $this->login($otherStoreAdmin);

        $response = $this->forwardSessionCookie($otherLogin)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->assertNotSame('AUTHORIZATION_DENIED', $response->json('error.code'));
        $this->assertStringNotContainsString($terminal->terminal_code, $response->getContent());
    }

    // The same-store case (already covered by other tests above) must
    // keep succeeding -- this middleware only rejects a genuine mismatch.
    public function test_terminal_resolution_succeeds_when_user_and_terminal_share_a_store(): void
    {
        [, $terminal, $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        $response->assertOk();
        $response->assertJson(['id' => $terminal->id]);
    }

    // A4 layering: EnsureUserIsActive must still run before
    // ComposeAuthoritativeContext -- an inactive user gets 401, never
    // 403, even while carrying an otherwise-valid, same-store terminal
    // credential.
    public function test_an_inactive_user_is_rejected_with_401_before_reaching_the_coherence_check(): void
    {
        [$admin, , $plaintext, $login] = $this->issueToken();
        $enroll = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);

        $admin->update(['active' => false]);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    // A3 final closeout 1: a Store-A administrator cannot read a Store-B
    // terminal's data via GET -- must be indistinguishable from "does not
    // exist," using the frozen TERMINAL_NOT_FOUND envelope, never leaking
    // Laravel's raw ModelNotFoundException/NotFoundHttpException shape.
    public function test_cross_store_terminal_get_does_not_expose_store_b_terminal_information(): void
    {
        $admin = $this->admin();
        $otherStoreTerminal = Terminal::factory()->create(); // different store, per factory default
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->getJson("/api/v1/terminals/{$otherStoreTerminal->id}");

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_FOUND']]);
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
        // No TerminalSummary field (terminal_code/status/activated_at) of
        // the actual Store-B record is present anywhere in the body.
        $this->assertStringNotContainsString($otherStoreTerminal->terminal_code, $response->getContent());
        $this->assertArrayNotHasKey('terminal_code', $response->json());
        $this->assertArrayNotHasKey('status', $response->json());
    }

    // A3 final closeout 2: a Store-A administrator cannot generate an
    // enrollment token for a Store-B terminal -- this is a distinct
    // attack path from consuming an already-issued token (see
    // test_a_valid_token_cannot_be_used_by_an_administrator_from_a_different_store
    // below), since issuance is where the token would first come into
    // existence at all.
    public function test_cross_store_enrollment_token_issuance_fails_and_creates_no_row(): void
    {
        $admin = $this->admin();
        $otherStoreTerminal = Terminal::factory()->unenrolled()->create();
        $login = $this->login($admin);

        $this->assertSame(0, TerminalEnrollmentToken::count());

        $response = $this->forwardSessionCookie($login)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $otherStoreTerminal->id]);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_FOUND']]);
        $this->assertSame(0, TerminalEnrollmentToken::count(), 'no enrollment-token row may be created for a cross-store terminal_id');
        $this->assertNull($response->json('token'), 'no plaintext token may ever be returned for a denied cross-store request');
    }

    // A3 final closeout 3: a Store-A administrator cannot revoke a
    // Store-B terminal's credential.
    public function test_cross_store_terminal_revoke_fails_and_leaves_store_b_terminal_unaffected(): void
    {
        $admin = $this->admin();
        [$otherAdmin, $otherTerminal, $otherPlaintext] = $this->issueToken();
        $otherLogin = $this->login($otherAdmin);
        $enroll = $this->forwardSessionCookie($otherLogin)->postJson('/api/v1/terminal/enroll', ['token' => $otherPlaintext]);
        $enroll->assertOk();
        $this->assertNotSame($admin->store_id, $otherTerminal->store_id);

        $credentialHashBefore = $otherTerminal->refresh()->credential_hash;
        $this->assertNull($otherTerminal->revoked_at);

        $login = $this->login($admin);
        $response = $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$otherTerminal->id}/revoke");

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_FOUND']]);

        $otherTerminal->refresh();
        $this->assertNull($otherTerminal->revoked_at, 'revoked_at must remain unchanged for a cross-store revoke attempt');
        $this->assertSame($credentialHashBefore, $otherTerminal->credential_hash, 'the existing Store-B credential must be unaffected');

        // The Store-B terminal's own credential (already established by
        // the enroll call above) still resolves normally afterward. A
        // FRESH login is captured here rather than reusing $otherLogin --
        // the intervening bare login($admin) call above ambiently
        // inherited whatever session cookie was still attached to $this
        // and, via session()->regenerate(), can silently repoint that
        // exact session ID at $admin instead of $otherAdmin.
        $freshOtherLogin = $this->login($otherAdmin);
        $current = $this->forwardSessionCookie($freshOtherLogin)->withTerminalCredential($enroll)->getJson('/api/v1/terminal/current');
        $current->assertOk();
        $current->assertJson(['id' => $otherTerminal->id]);
    }

    // A3 closeout item 2.1: Store-A administrator lists only Store-A terminals.
    public function test_terminal_list_only_returns_the_actors_own_store(): void
    {
        $admin = $this->admin();
        $ownTerminal = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $otherStoreTerminal = Terminal::factory()->create(); // different store, per factory default
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/terminals');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownTerminal->id));
        $this->assertFalse($ids->contains($otherStoreTerminal->id), 'another store\'s terminal must never appear in the list');
    }

    // A3 closeout item 2.5: request data cannot override the actor's authoritative store scope.
    public function test_request_body_store_id_cannot_override_the_actors_authoritative_store(): void
    {
        $admin = $this->admin();
        $otherStoreTerminal = Terminal::factory()->create(); // a different store's terminal
        $login = $this->login($admin);

        // Even if a client tried to smuggle a different store_id/terminal_id
        // combination, the controller never reads store_id from the
        // request at all -- only $actor->store_id is ever used. This is
        // structurally guaranteed, not merely a validation rule; proven by
        // confirming the "foreign" terminal remains unreachable regardless
        // of what the request body claims.
        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', [
            'terminal_id' => $otherStoreTerminal->id,
            'store_id' => $admin->store_id, // not a real field on this operation; must be ignored
        ]);

        $response->assertStatus(404);
    }

    // A3 closeout item 3: a valid token belonging to another store cannot
    // be used by an authenticated administrator from the wrong store, and
    // the attempt does not burn the token for its legitimate owner.
    public function test_a_valid_token_cannot_be_used_by_an_administrator_from_a_different_store(): void
    {
        [, $terminal, $plaintext] = $this->issueToken();

        $otherStoreAdmin = $this->admin();
        $otherLogin = $this->login($otherStoreAdmin);
        $this->assertNotSame($terminal->store_id, $otherStoreAdmin->store_id);

        $crossStoreAttempt = $this->forwardSessionCookie($otherLogin)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);
        $crossStoreAttempt->assertStatus(409);
        $crossStoreAttempt->assertJson(['error' => ['code' => 'ENROLLMENT_TOKEN_INVALID']]);

        // The token was NOT burned by the wrong-store attempt -- the
        // legitimate (same-store) administrator can still use it.
        $tokenRow = TerminalEnrollmentToken::first();
        $this->assertNull($tokenRow->used_at, 'a cross-store attempt must not consume the token');

        $originalStoreAdmin = User::where('store_id', $terminal->store_id)->where('role', 'ADMIN')->first();
        $originalLogin = $this->login($originalStoreAdmin);
        $legitimateAttempt = $this->forwardSessionCookie($originalLogin)->postJson('/api/v1/terminal/enroll', ['token' => $plaintext]);
        $legitimateAttempt->assertOk();
    }

    // A3 closeout item 5: eager consumption survives a phase-2 (credential
    // issuance) failure -- the token stays consumed, never rolled back.
    public function test_token_stays_consumed_even_if_credential_issuance_subsequently_fails(): void
    {
        $admin = $this->admin();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id]);
        $plaintext = Str::random(64);

        DB::table('terminal_enrollment_tokens')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $admin->store_id, 'terminal_id' => $terminal->id,
            'token_hash' => hash('sha256', $plaintext), 'created_by' => $admin->id,
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);

        $service = new TerminalEnrollmentService;

        // Phase 1 alone: claim the token.
        $resolvedTerminalId = $service->consumeToken($plaintext, $admin->store_id);
        $this->assertSame($terminal->id, $resolvedTerminalId);
        $this->assertNotNull(TerminalEnrollmentToken::first()->used_at, 'phase 1 must commit used_at on its own');

        // Phase 2, forced to fail: a terminal id that cannot resolve.
        try {
            $service->issueCredential('00000000-0000-0000-0000-000000000000');
            $this->fail('expected issueCredential() to fail for a non-existent terminal');
        } catch (ModelNotFoundException) {
            // expected
        }

        // The token remains consumed regardless -- ADR-011's "regardless
        // of outcome" is not merely a documentation claim.
        $this->assertNotNull(TerminalEnrollmentToken::first()->used_at, 'the token must stay consumed even though credential issuance failed');
        $this->assertNull($terminal->refresh()->credential_hash, 'the terminal itself must remain unenrolled since issuance never completed');
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

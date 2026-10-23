<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Terminal;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Services\License\Entitlement;
use App\Support\DeploymentId;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * ADR-015: remote till approval. A signed-in person at an un-enrolled browser asks to become a till; an administrator elsewhere
 * approves by typing the code read from that browser; the browser then claims its credential. Nothing becomes a till without an
 * administrator's approval of that specific request.
 */
class TerminalPairingHttpTest extends PostgresSchemaTestCase
{
    private function as(User $user): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array{admin: User, cashier: User, till: Terminal} */
    private function shop(): array
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $till = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id, 'terminal_code' => 'TILL-1']);

        return ['admin' => $admin, 'cashier' => $cashier, 'till' => $till];
    }

    private function ask(User $who): TestResponse
    {
        return $this->as($who)->postJson('/api/v1/terminal/pairing-requests')->assertStatus(201);
    }

    public function test_a_cashier_can_ask_and_gets_a_code_to_read_out_and_a_secret_the_server_keeps_only_hashed(): void
    {
        ['cashier' => $cashier] = $this->shop();

        $response = $this->ask($cashier);

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $response->json('code'));
        $row = TerminalPairingRequest::sole();
        $this->assertSame('PENDING', $row->status);
        $this->assertSame($cashier->id, $row->requested_by);
        $this->assertNotSame($response->json('secret'), $row->secret_hash);
        $this->assertStringNotContainsString(str_replace('-', '', $response->json('code')), json_encode($row->getAttributes()));
        $this->assertSame(1, AuditEvent::where('event_type', 'TERMINAL_PAIRING_REQUESTED')->count());
    }

    public function test_the_whole_flow_an_admin_elsewhere_approves_with_the_code_and_the_browser_becomes_the_till(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'till' => $till] = $this->shop();
        $asked = $this->ask($cashier);

        // Waiting: the till browser polls and is told to keep waiting.
        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => $asked->json('secret')])
            ->assertStatus(202)->assertJson(['status' => 'PENDING']);

        // The admin, on another machine, sees a request (but not its code) and approves it with the code read out to them.
        $list = $this->as($admin)->getJson('/api/v1/terminal/pairing-requests')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertArrayNotHasKey('code', $list->json('data.0'));
        $this->assertSame($cashier->id, $list->json('data.0.requested_by.id'));
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => strtolower($asked->json('code')), 'terminal_id' => $till->id])
            ->assertOk()->assertJson(['status' => 'APPROVED', 'terminal_id' => $till->id]);

        // The till browser's next poll hands it the credential, once.
        $claimed = $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => $asked->json('secret')]);
        $claimed->assertOk()->assertJson(['id' => $till->id, 'terminal_code' => 'TILL-1']);
        $credential = collect($claimed->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal')?->getValue();
        $this->assertNotEmpty($credential);
        $this->assertNotNull($till->refresh()->credential_hash);
        $this->assertNotNull($till->activated_at);
        $this->assertSame('CONSUMED', TerminalPairingRequest::sole()->status);

        // ...and it works as the till: terminalCurrent answers for it.
        $this->as($cashier)->withUnencryptedCookie('tindaflow_terminal', $credential)->getJson('/api/v1/terminal/current')->assertOk()->assertJson(['id' => $till->id]);

        // The claim cannot be used twice.
        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => $asked->json('secret')])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'PAIRING_REQUEST_INVALID']]);

        // Audited: who asked, who approved, which till was enrolled; never the code, secret or credential.
        foreach (['TERMINAL_PAIRING_REQUESTED', 'TERMINAL_PAIRING_APPROVED', 'TERMINAL_ENROLLED'] as $event) {
            $this->assertSame(1, AuditEvent::where('event_type', $event)->count(), $event);
        }
        $this->assertSame($admin->id, AuditEvent::where('event_type', 'TERMINAL_PAIRING_APPROVED')->sole()->actor_user_id);
        $log = json_encode(AuditEvent::all()->toArray());
        foreach ([$asked->json('secret'), $credential, str_replace('-', '', $asked->json('code'))] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_a_wrong_code_is_refused_and_five_of_them_decline_the_request(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'till' => $till] = $this->shop();
        $asked = $this->ask($cashier);

        foreach (range(1, 4) as $try) {
            $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => 'WRONG-000', 'terminal_id' => $till->id])
                ->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->assertSame('PENDING', TerminalPairingRequest::sole()->status);

        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => 'WRONG-000', 'terminal_id' => $till->id])->assertStatus(422);
        $this->assertSame('REJECTED', TerminalPairingRequest::sole()->status);
        // Even the right code no longer works.
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $till->id])->assertStatus(409);
        $this->assertNull($till->refresh()->credential_hash);

        // Every refused code is on the record: who tried, which request, which attempt, and that the fifth declined it.
        $refused = AuditEvent::where('event_type', 'TERMINAL_PAIRING_CODE_REFUSED')->orderBy('occurred_at')->get();
        $this->assertSame(5, $refused->count());
        $this->assertSame($admin->id, $refused->first()->actor_user_id);
        $this->assertSame([1, 2, 3, 4, 5], $refused->pluck('after_metadata.attempts')->all());
        $this->assertSame([false, false, false, false, true], $refused->pluck('after_metadata.declined')->all());
    }

    public function test_only_someone_who_can_manage_terminals_may_list_approve_or_decline(): void
    {
        ['cashier' => $cashier, 'till' => $till] = $this->shop();
        $manager = User::factory()->manager()->create(['store_id' => $cashier->store_id]);
        $asked = $this->ask($cashier);

        foreach ([$cashier, $manager] as $user) {
            $this->as($user)->getJson('/api/v1/terminal/pairing-requests')->assertStatus(403);
            $this->as($user)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $till->id])->assertStatus(403);
            $this->as($user)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/reject")->assertStatus(403);
        }
        $this->assertSame('PENDING', TerminalPairingRequest::sole()->status);
    }

    public function test_the_claim_needs_the_secret_and_the_same_signed_in_person(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'till' => $till] = $this->shop();
        $other = User::factory()->create(['store_id' => $cashier->store_id]);
        $asked = $this->ask($cashier);
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $till->id])->assertOk();

        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => 'not-the-secret'])->assertStatus(409);
        $this->as($other)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => $asked->json('secret')])->assertStatus(409);
        $this->assertNull($till->refresh()->credential_hash, 'nothing was enrolled by a wrong claim');

        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/claim", ['secret' => $asked->json('secret')])->assertOk();
    }

    public function test_a_declined_or_expired_request_cannot_be_approved_or_claimed(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'till' => $till] = $this->shop();
        $declined = $this->ask($cashier);
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$declined->json('id')}/reject")->assertOk()->assertJson(['status' => 'REJECTED']);

        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$declined->json('id')}/approve", ['code' => $declined->json('code'), 'terminal_id' => $till->id])->assertStatus(409);
        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$declined->json('id')}/claim", ['secret' => $declined->json('secret')])->assertStatus(409);

        $expiring = $this->ask($cashier);
        $this->travel(16)->minutes();
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$expiring->json('id')}/approve", ['code' => $expiring->json('code'), 'terminal_id' => $till->id])->assertStatus(409);
        $this->as($cashier)->postJson("/api/v1/terminal/pairing-requests/{$expiring->json('id')}/claim", ['secret' => $expiring->json('secret')])->assertStatus(409);
        $this->assertCount(0, $this->as($admin)->getJson('/api/v1/terminal/pairing-requests')->json('data'), 'an expired request is no longer listed');
        $this->assertSame(1, AuditEvent::where('event_type', 'TERMINAL_PAIRING_DECLINED')->count());
    }

    public function test_asking_again_replaces_the_earlier_request(): void
    {
        ['cashier' => $cashier] = $this->shop();
        $first = $this->ask($cashier);
        $second = $this->ask($cashier);

        $this->assertSame('REJECTED', TerminalPairingRequest::find($first->json('id'))->status);
        $this->assertSame('PENDING', TerminalPairingRequest::find($second->json('id'))->status);
        $this->assertSame(1, TerminalPairingRequest::where('status', 'PENDING')->count());
    }

    public function test_only_twenty_requests_may_wait_in_a_store_at_once(): void
    {
        ['admin' => $admin] = $this->shop();
        foreach (range(1, 20) as $n) {
            TerminalPairingRequest::create([
                'store_id' => $admin->store_id, 'requested_by' => User::factory()->create(['store_id' => $admin->store_id])->id,
                'code_hash' => "c{$n}", 'secret_hash' => "s{$n}", 'status' => 'PENDING', 'expires_at' => now()->addMinutes(10),
            ]);
        }
        $latecomer = User::factory()->create(['store_id' => $admin->store_id]);

        $this->as($latecomer)->postJson('/api/v1/terminal/pairing-requests')->assertStatus(429)->assertJson(['error' => ['code' => 'PAIRING_REQUEST_LIMIT']]);
    }

    public function test_another_stores_requests_and_terminals_are_out_of_reach(): void
    {
        ['cashier' => $cashier, 'till' => $till] = $this->shop();
        $stranger = User::factory()->admin()->create();
        $strangersTill = Terminal::factory()->unenrolled()->create(['store_id' => $stranger->store_id]);
        $asked = $this->ask($cashier);

        $this->assertCount(0, $this->as($stranger)->getJson('/api/v1/terminal/pairing-requests')->json('data'));
        $this->as($stranger)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $strangersTill->id])->assertStatus(409);
        // An administrator of the right store cannot attach a request to another store's terminal either.
        $admin = User::factory()->admin()->create(['store_id' => $cashier->store_id]);
        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $strangersTill->id])
            ->assertStatus(404)->assertJson(['error' => ['code' => 'TERMINAL_NOT_FOUND']]);
        $this->assertNull($till->refresh()->credential_hash);
    }

    public function test_approving_a_revoked_till_needs_a_free_licensed_seat(): void
    {
        ['admin' => $admin, 'cashier' => $cashier] = $this->shop();
        $revoked = Terminal::factory()->create(['store_id' => $admin->store_id, 'revoked_at' => now()]);
        $this->licenseForOneTill();
        $asked = $this->ask($cashier);

        $this->as($admin)->postJson("/api/v1/terminal/pairing-requests/{$asked->json('id')}/approve", ['code' => $asked->json('code'), 'terminal_id' => $revoked->id])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'TERMINAL_LIMIT_REACHED']]);
        $this->assertSame('PENDING', TerminalPairingRequest::sole()->status, 'the request stays waiting');
    }

    /** Trusts a freshly generated vendor key and installs a license for this installation that allows one till. */
    private function licenseForOneTill(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $payload = json_encode(['deployment' => DeploymentId::current(), 'max_terminals' => 1, 'expires_at' => null]);
        $token = Entitlement::encode($payload).'.'.Entitlement::encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
        config(['tindaflow.license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'tindaflow.license.token' => $token, 'tindaflow.license.path' => '']);
    }

    public function test_it_needs_a_session(): void
    {
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/terminal/pairing-requests')->assertStatus(401);
        $this->postJson("/api/v1/terminal/pairing-requests/{$id}/claim", ['secret' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/terminal/pairing-requests')->assertStatus(401);
        $this->postJson("/api/v1/terminal/pairing-requests/{$id}/approve", [])->assertStatus(401);
        $this->postJson("/api/v1/terminal/pairing-requests/{$id}/reject")->assertStatus(401);
    }
}

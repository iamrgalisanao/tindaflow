<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * terminalCreate (POST /terminals): the Terminals screen's "Add terminal". Real HTTP through the full middleware chain,
 * with the login session cookie forwarded explicitly (see TerminalEnrollmentTest).
 */
class TerminalCreateHttpTest extends PostgresSchemaTestCase
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

    public function test_an_administrator_creates_an_active_un_enrolled_terminal_in_their_store_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->forwardSessionCookie($this->login($admin))
            ->postJson('/api/v1/terminals', ['terminal_code' => '  TILL-1 ']);

        $response->assertStatus(201)->assertJson(['terminal_code' => 'TILL-1', 'status' => 'ACTIVE']);
        $terminal = Terminal::where('store_id', $admin->store_id)->sole();
        $this->assertSame($terminal->id, $response->json('id'));
        $this->assertNull($terminal->activated_at, 'enrolment sets it, not creation');
        $this->assertNull($terminal->credential_hash);
        $event = AuditEvent::where('event_type', 'TERMINAL_CREATED')->sole();
        $this->assertSame($terminal->id, $event->entity_id);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($admin->store_id, $event->store_id);
    }

    public function test_the_new_terminal_can_be_enrolled_through_the_normal_token_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);
        $id = $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->json('id');

        $token = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $id])->assertStatus(201)->json('token');
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $token])
            ->assertOk()
            ->assertJson(['id' => $id, 'terminal_code' => 'TILL-1']);
    }

    public function test_a_duplicate_name_in_the_same_store_is_rejected_ignoring_letter_case_and_creates_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        Terminal::factory()->create(['store_id' => $admin->store_id, 'terminal_code' => 'TILL-1']);

        $response = $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/terminals', ['terminal_code' => 'till-1']);

        $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('terminal_code', $response->json('error.details'));
        $this->assertSame(1, Terminal::where('store_id', $admin->store_id)->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'TERMINAL_CREATED')->count());
    }

    public function test_the_same_name_may_exist_in_another_store(): void
    {
        $admin = User::factory()->admin()->create();
        Terminal::factory()->create(['terminal_code' => 'TILL-1']); // another store

        $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->assertStatus(201);
    }

    #[DataProvider('invalidNames')]
    public function test_an_empty_blank_missing_or_too_long_name_is_rejected(array $body): void
    {
        $admin = User::factory()->admin()->create();

        $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/terminals', $body)
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertSame(0, Terminal::count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidNames(): array
    {
        return [
            'missing' => [[]],
            'blank' => [['terminal_code' => '   ']],
            'too long' => [['terminal_code' => str_repeat('A', 41)]],
        ];
    }

    public function test_a_user_without_terminal_manage_is_denied_and_nothing_is_created(): void
    {
        $user = User::factory()->create(['role' => 'CASHIER']);

        $this->forwardSessionCookie($this->login($user))->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->assertStatus(403);
        $this->assertSame(0, Terminal::count());
    }
}

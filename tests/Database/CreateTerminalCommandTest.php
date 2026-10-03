<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * `tindaflow:create-terminal`: the server-console way to create a till (the Terminals screen's "Add terminal" is the
 * browser way; the demo seeder refuses to run in production).
 */
class CreateTerminalCommandTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return array{status: int, output: string} */
    private function create(string $code, array $options = []): array
    {
        $status = Artisan::call('tindaflow:create-terminal', ['code' => $code] + $options);

        return ['status' => $status, 'output' => Artisan::output()];
    }

    public function test_it_creates_an_active_un_enrolled_till_for_the_only_store_and_audits_it(): void
    {
        $store = Store::factory()->create(['name' => 'Aling Nena']);

        $result = $this->create('TILL-1');

        $this->assertSame(0, $result['status']);
        $terminal = Terminal::where('store_id', $store->id)->sole();
        $this->assertSame('TILL-1', $terminal->terminal_code);
        $this->assertSame('ACTIVE', $terminal->status);
        $this->assertNull($terminal->activated_at, 'enrolment sets it, not creation');
        $this->assertNull($terminal->credential_hash);
        $this->assertNull($terminal->revoked_at);
        $event = AuditEvent::where('event_type', 'TERMINAL_CREATED')->sole();
        $this->assertSame($terminal->id, $event->entity_id);
        $this->assertNull($event->actor_user_id);
        $this->assertStringContainsString('Enrollment token', $result['output'], 'it says what to do next');
    }

    public function test_the_till_it_creates_can_really_be_enrolled_and_used_to_open_a_shift(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);
        $this->create('TILL-1');
        $terminal = Terminal::where('store_id', $store->id)->sole();

        $token = $this->asUser($admin)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $token->assertStatus(201);
        $enrolled = $this->asUser($admin)->postJson('/api/v1/terminal/enroll', ['token' => $token->json('token')]);
        $enrolled->assertOk();
        $opened = $this->asUser($cashier, $enrolled)->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'], $this->key());

        $opened->assertStatus(201);
        $this->assertSame($terminal->id, $opened->json('shift.terminal_id'));
        $this->assertNotNull($terminal->fresh()->activated_at);
        $this->assertSame(1, Shift::where('terminal_id', $terminal->id)->count());
    }

    public function test_a_second_till_with_the_same_code_is_refused_without_regard_to_case(): void
    {
        Store::factory()->create();
        $this->create('TILL-1');

        $result = $this->create('till-1');

        $this->assertSame(1, $result['status']);
        $this->assertSame(1, Terminal::count());
        $this->assertSame(1, AuditEvent::where('event_type', 'TERMINAL_CREATED')->count());
    }

    public function test_a_blank_or_over_long_code_is_refused(): void
    {
        Store::factory()->create();

        $this->assertSame(1, $this->create('   ')['status']);
        $this->assertSame(1, $this->create(Str::random(41))['status']);
        $this->assertSame(0, Terminal::count());
    }

    public function test_with_no_store_yet_it_says_to_create_the_administrator_first(): void
    {
        $result = $this->create('TILL-1');

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('db:seed', $result['output']);
    }

    public function test_with_two_stores_it_needs_to_be_told_which_and_then_accepts_a_name_or_an_id(): void
    {
        $first = Store::factory()->create(['name' => 'North Shop']);
        $second = Store::factory()->create(['name' => 'South Shop']);

        $ambiguous = $this->create('TILL-1');
        $this->assertSame(1, $ambiguous['status']);
        $this->assertSame(0, Terminal::count());

        $this->assertSame(0, $this->create('TILL-1', ['--store' => 'south shop'])['status']);
        $this->assertSame(0, $this->create('TILL-1', ['--store' => $first->id])['status']);
        $this->assertSame($second->id, Terminal::where('store_id', $second->id)->sole()->store_id);
        $this->assertSame(1, Terminal::where('store_id', $first->id)->count(), 'the same code is allowed in a different store');

        $this->assertSame(1, $this->create('TILL-2', ['--store' => 'No Such Shop'])['status']);
        $this->assertSame(2, Terminal::count());
    }
}

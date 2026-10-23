<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Terminal;
use App\Services\Terminal\TerminalEnrollmentService;
use App\Services\Terminal\TerminalUsageTracker;
use Illuminate\Http\Request;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * A till credential used from two different computers in turn is flagged (TerminalUsageTracker): a tripwire for an administrator, never a
 * lock. A single change of address or browser is not alternation; going A, B, A again within half an hour is.
 */
class TerminalSharedUseHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const OFFICE = ['ip' => '10.0.0.5', 'agent' => 'Chrome/150 Windows'];

    private const HOME = ['ip' => '203.0.113.9', 'agent' => 'Chrome/150 Mac'];

    /** One request to the till as if it came from $client. */
    private function useTillFrom(array $w, array $client): void
    {
        $this->asUser($w['cashier'], $w['enroll1'])
            ->withServerVariables(['REMOTE_ADDR' => $client['ip']])
            ->withHeader('User-Agent', $client['agent'])
            ->getJson('/api/v1/terminal/current')
            ->assertOk();
    }

    private function till(array $w): Terminal
    {
        return $w['t1']->refresh();
    }

    public function test_the_first_use_records_where_and_when(): void
    {
        $w = $this->world();

        $this->useTillFrom($w, self::OFFICE);

        $till = $this->till($w);
        $this->assertSame(self::OFFICE['ip'], $till->last_seen_ip);
        $this->assertSame(self::OFFICE['agent'], $till->last_seen_user_agent);
        $this->assertNotNull($till->last_seen_at);
        $this->assertNull($till->shared_use_suspected_at);
    }

    public function test_the_same_computer_again_raises_nothing(): void
    {
        $w = $this->world();

        foreach (range(1, 4) as $n) {
            $this->useTillFrom($w, self::OFFICE);
        }

        $this->assertNull($this->till($w)->shared_use_suspected_at);
        $this->assertSame(0, AuditEvent::where('event_type', 'TERMINAL_SHARED_USE_SUSPECTED')->count());
    }

    public function test_a_till_that_simply_moved_is_not_flagged(): void
    {
        $w = $this->world();

        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME);
        $this->useTillFrom($w, self::HOME);

        $this->assertNull($this->till($w)->shared_use_suspected_at, 'one change of address is a move, not two computers');
        $this->assertSame(self::HOME['ip'], $this->till($w)->last_seen_ip);
    }

    public function test_two_computers_taking_turns_are_flagged_once_and_audited(): void
    {
        $w = $this->world();

        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME);
        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME); // alternating again inside the hour: still one flag

        $this->assertNotNull($this->till($w)->shared_use_suspected_at);
        $event = AuditEvent::where('event_type', 'TERMINAL_SHARED_USE_SUSPECTED')->sole();
        $this->assertSame($w['t1']->id, $event->entity_id);
        $this->assertSame($w['t1']->id, $event->terminal_id);
        $this->assertSame($w['cashier']->id, $event->actor_user_id);
        $this->assertSame(self::OFFICE['ip'], $event->after_metadata['ip_address']);
        $this->assertSame(self::HOME['ip'], $event->after_metadata['other_ip_address']);
        // An administrator sees it in the terminals list.
        $listed = collect($this->asUser($w['admin'])->getJson('/api/v1/terminals')->json('data'))->firstWhere('id', $w['t1']->id);
        $this->assertNotNull($listed['shared_use_suspected_at']);
        $this->assertSame(self::HOME['ip'], $listed['last_seen_ip'], 'the list shows the most recent computer');
    }

    public function test_a_long_gap_between_the_turns_is_not_flagged(): void
    {
        $w = $this->world();

        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME);
        $this->travel(31)->minutes();
        $this->useTillFrom($w, self::OFFICE);

        $this->assertNull($this->till($w)->shared_use_suspected_at);
    }

    public function test_a_flag_can_be_raised_again_after_an_hour(): void
    {
        $w = $this->world();
        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME);
        $this->useTillFrom($w, self::OFFICE);
        $this->travel(61)->minutes();

        $this->useTillFrom($w, self::HOME);
        $this->useTillFrom($w, self::OFFICE);

        $this->assertSame(2, AuditEvent::where('event_type', 'TERMINAL_SHARED_USE_SUSPECTED')->count());
    }

    public function test_enrolling_the_till_again_clears_the_flag_because_every_copy_of_the_old_credential_stops_working(): void
    {
        $w = $this->world();
        $this->useTillFrom($w, self::OFFICE);
        $this->useTillFrom($w, self::HOME);
        $this->useTillFrom($w, self::OFFICE);
        $this->assertNotNull($this->till($w)->shared_use_suspected_at);

        app(TerminalEnrollmentService::class)->issueCredential($w['t1']->id, $w['admin']->id);

        $till = $this->till($w);
        $this->assertNull($till->shared_use_suspected_at);
        $this->assertNull($till->last_seen_signature);
    }

    public function test_the_tracker_can_never_break_a_request(): void
    {
        $w = $this->world();
        $broken = new class extends Request
        {
            public function ip(): ?string
            {
                throw new \RuntimeException('the network layer misbehaved');
            }
        };

        app(TerminalUsageTracker::class)->record($w['t1'], $broken);

        $this->assertTrue(true, 'record() swallowed the failure');
    }
}

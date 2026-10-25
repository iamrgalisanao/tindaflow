<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Services\Overrides\OverrideCodeService;
use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Database\Concerns\BuildsSalesScenario;
use Tests\TestCase;

/**
 * ADR-016 slice 3.3, OVR-002: the wrong-code count is COMMITTED, not merely written. The other override tests run inside the suite's
 * rolled-back transaction and could not tell the difference, so this class (like the concurrency tests) does not wrap its tests: each
 * request commits for real and a SECOND connection reads what it left. It also proves the guard that keeps the count out of any
 * surrounding transaction: a request wrapped in a transaction that rolls back is refused outright rather than allowed to erase the count.
 */
class CreditOverrideLockoutTest extends TestCase
{
    use BuildsSalesScenario;

    private const DATABASE = 'tindaflow_concurrency_test';

    private const CODE = '482916';

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);

        config(['tindaflow.login_throttle.max_attempts' => 100000, 'tindaflow.credit_override.throttle_attempts' => 1000]);
        // A second, independent connection to the same database: it sees only what has been COMMITTED.
        config(['database.connections.observer' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('observer');

        parent::tearDown();
    }

    /** @return array{0: array<string, mixed>, 1: Employee} */
    private function scene(): array
    {
        $w = $this->world();
        app(OverrideCodeService::class)->setOwn($w['manager'], 'password', self::CODE);

        return [$w, Employee::factory()->create(['store_id' => $w['storeId']])];
    }

    private function wrong(array $w, Employee $employee, string $code = '000001')
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $w['manager']->id, 'code' => $code, 'employee_id' => $employee->id, 'amount' => '200.00',
        ]);
    }

    /** @return object{failed_attempts: int, locked_until: ?string} */
    private function seenByAnotherConnection(array $w): object
    {
        return DB::connection('observer')->table('user_override_codes')->where('user_id', $w['manager']->id)->first(['failed_attempts', 'locked_until']);
    }

    public function test_each_wrong_code_is_committed_where_another_connection_can_see_it_and_the_fifth_locks_the_approver(): void
    {
        [$w, $employee] = $this->scene();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->wrong($w, $employee)->assertStatus(422);
            $this->assertSame($attempt, (int) $this->seenByAnotherConnection($w)->failed_attempts, "wrong code {$attempt} is committed");
        }
        $this->wrong($w, $employee)->assertStatus(423)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_LOCKED');

        $seen = $this->seenByAnotherConnection($w);
        $this->assertNotNull($seen->locked_until, 'the lock is committed');
        $this->assertSame(5, DB::connection('observer')->table('audit_events')->where('event_type', 'CREDIT_OVERRIDE_REFUSED')->count());
        $this->assertSame(1, DB::connection('observer')->table('audit_events')->where('event_type', 'CREDIT_OVERRIDE_LOCKED')->count());

        // Locked means locked for the right code too, until the fifteen minutes are over.
        $this->wrong($w, $employee, self::CODE)->assertStatus(423);
        $this->travelTo(now()->addMinutes(16));
        $this->wrong($w, $employee, self::CODE)->assertStatus(201);
        $this->assertSame(0, (int) $this->seenByAnotherConnection($w)->failed_attempts);
    }

    public function test_a_failure_after_the_check_cannot_erase_the_count_because_nothing_around_it_holds_a_transaction(): void
    {
        [$w, $employee] = $this->scene();

        // A middleware that lets the request run and then blows up: the response is a 500, as if anything later in the request had failed.
        app(Kernel::class)->pushMiddleware(FailsAfterTheController::class);

        $this->wrong($w, $employee)->assertStatus(500);

        $this->assertSame(1, (int) $this->seenByAnotherConnection($w)->failed_attempts, 'the count was committed before the request failed');
        $this->assertSame(1, AuditEvent::where('event_type', 'CREDIT_OVERRIDE_REFUSED')->count());
    }

    public function test_a_request_wrapped_in_a_transaction_that_rolls_back_is_refused_outright_so_it_can_never_erase_the_count(): void
    {
        [$w, $employee] = $this->scene();

        app(Kernel::class)->pushMiddleware(RollsBackEverything::class);

        // The guard stops the verification before a code is even looked at: no attempt is counted, none can be erased.
        $this->wrong($w, $employee)->assertStatus(500);

        $this->assertSame(0, (int) $this->seenByAnotherConnection($w)->failed_attempts);
        $this->assertSame(0, DB::connection('observer')->table('credit_override_grants')->count());
    }
}

/** A stand-in for "anything later in the request fails": the controller has finished, then the request dies. */
final class FailsAfterTheController
{
    public function handle(Request $request, Closure $next): Response
    {
        $next($request);

        throw new \RuntimeException('something later in the request failed');
    }
}

/** A stand-in for a future wrapper (an `ATOMIC_REQUESTS`-style middleware) that would roll the whole request back on an error status. */
final class RollsBackEverything
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::beginTransaction();
        try {
            return $next($request);
        } finally {
            DB::rollBack();
        }
    }
}

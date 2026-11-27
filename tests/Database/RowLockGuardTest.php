<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\Overrides\OverrideCodeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;
use Tests\TestCase;

/**
 * Two row locks that no ordinary test can see (invariants.md OVR-002, SLIP-001). A race may or may not show a missing lock, so these
 * do not race: a SECOND connection holds the row the way the other side of the race would, and the request must wait for it. With a
 * short lock timeout, waiting shows as a failed request that changed nothing; without the lock under test it would simply succeed.
 * Like the lockout and concurrency tests this class commits for real, so that the second connection sees the rows.
 */
class RowLockGuardTest extends TestCase
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
        config(['database.connections.holder' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        $this->release();
        DB::purge('holder');
        DB::statement('SET lock_timeout = 0');

        parent::tearDown();
    }

    private function hold(string $sql, string $id): void
    {
        DB::connection('holder')->beginTransaction();
        $this->assertCount(1, DB::connection('holder')->select($sql, [$id]), 'the row to hold exists');
        DB::statement("SET lock_timeout = '750ms'");
    }

    private function release(): void
    {
        while (DB::connection('holder')->transactionLevel() > 0) {
            DB::connection('holder')->rollBack();
        }
    }

    public function test_checking_an_override_code_waits_for_the_row_that_counts_the_wrong_ones(): void
    {
        $w = $this->world();
        app(OverrideCodeService::class)->setOwn($w['manager'], 'password', self::CODE);
        $employee = Employee::factory()->create(['store_id' => $w['storeId']]);
        // The RIGHT code, with nothing to count: only the lock taken to check it can make this wait.
        $approve = fn () => $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/credit-overrides', [
            'approver_id' => $w['manager']->id, 'code' => self::CODE, 'employee_id' => $employee->id, 'amount' => '200.00',
        ]);
        $approve()->assertStatus(201); // it works while nobody holds the row

        $this->hold('SELECT user_id FROM user_override_codes WHERE user_id = ? FOR UPDATE', $w['manager']->id);
        $approve()->assertStatus(500);
        $this->assertSame(1, DB::table('credit_override_grants')->count(), 'nothing was approved while another check held the row');

        $this->release();
        $approve()->assertStatus(201);
    }

    public function test_opening_a_shift_waits_while_the_document_mode_is_being_switched(): void
    {
        $w = $this->world();
        $till = Terminal::factory()->create(['store_id' => $w['storeId']]);
        $login = $this->login($w['admin']);
        $token = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $till->id])->json('token');
        $enrolment = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $token]);
        $open = fn () => $this->asUser($w['admin'], $enrolment)->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'], $this->key());

        // NO KEY UPDATE stands in the way of the shared lock under test, and not of the key-share locks that foreign keys take.
        $this->hold('SELECT id FROM stores WHERE id = ? FOR NO KEY UPDATE', $w['storeId']);
        $open()->assertStatus(500);
        $this->assertSame(0, Shift::where('terminal_id', $till->id)->count(), 'no shift opened during the switch');

        $this->release();
        $open()->assertSuccessful();
        $this->assertSame(1, Shift::where('terminal_id', $till->id)->count());
    }
}

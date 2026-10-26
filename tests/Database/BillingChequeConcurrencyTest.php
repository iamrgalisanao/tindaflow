<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\Employees\BillingPartyResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-016 slice 4.2, BILL-002 / BILL-007: two cheques cannot both be allocated against the same owed amount, and one cheque is returned
 * once, even when the requests race. Real OS processes with their own connections are released together (the allocation limit is a
 * sum across rows, which no database constraint can express, so this test is the proof). Like the other concurrency tests this does
 * not extend PostgresSchemaTestCase: its fixtures are committed and visible to the workers.
 */
class BillingChequeConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private string $scratchDir;

    private string $storeId;

    private string $managerId;

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);

        $shift = Shift::factory()->create();
        $this->storeId = $shift->fiscalDay->store_id;
        $this->managerId = User::factory()->manager()->create(['store_id' => $this->storeId])->id;
        $party = app(BillingPartyResolver::class)->forStore($this->storeId);
        $employee = Employee::factory()->create(['store_id' => $this->storeId]);
        // A committed P500.00 charge in the 1st-15th period of October 2026 (the sale it belongs to exists, as it always does).
        $sale = Sale::factory()->create([
            'store_id' => $this->storeId, 'terminal_id' => $shift->terminal_id, 'fiscal_day_id' => $shift->fiscal_day_id, 'shift_id' => $shift->id, 'cashier_id' => $shift->cashier_id,
        ]);
        DB::table('employee_credit_entries')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'employee_id' => $employee->id, 'entry_type' => 'CHARGE', 'amount' => '500.00',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'sale_id' => $sale->id, 'employee_code' => $employee->employee_code,
            'employee_name' => 'Ana Reyes', 'employee_type' => 'DIRECT', 'billing_party_id' => $party->id, 'recorded_at' => '2026-10-05 12:00:00+08',
        ]);

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_cheque_race_'.Str::random(8);
        mkdir($this->scratchDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function recordJob(string $number, string $amount): array
    {
        return ['action' => 'record', 'user_id' => $this->managerId, 'cheque' => [
            'cheque_number' => $number, 'bank' => 'BDO', 'cheque_date' => now()->toDateString(), 'amount' => $amount, 'received_on' => now()->toDateString(),
            'allocations' => [['period_start' => '2026-10-01', 'amount' => $amount]],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/billing_cheque_race_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            file_put_contents($this->scratchDir."/job_{$index}.json", json_encode($job));
            $processes[$index] = Process::start([
                PHP_BINARY, $script, $this->scratchDir."/job_{$index}.json", $this->scratchDir."/ready_{$index}",
                $go, $this->scratchDir."/out_{$index}.json", self::DATABASE,
            ]);
        }

        $deadline = microtime(true) + 30;
        while (count(glob($this->scratchDir.'/ready_*') ?: []) < count($jobs)) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $outcomes = [];
        foreach ($processes as $index => $process) {
            $result = $process->wait();
            $this->assertTrue($result->successful(), "worker {$index} failed: {$result->errorOutput()}");
            $outcomes[] = json_decode(file_get_contents($this->scratchDir."/out_{$index}.json"), true);
        }

        return $outcomes;
    }

    public function test_two_cheques_racing_for_the_same_owed_amount_cannot_both_be_recorded(): void
    {
        $outcomes = $this->race([$this->recordJob('A-1', '400.00'), $this->recordJob('A-2', '400.00')]);

        $this->assertSame(['ChequeAllocationExceedsOwedException', 'success'], collect($outcomes)->map(fn ($o) => $o['class'] ?? $o['outcome'])->sort()->values()->all(), json_encode($outcomes));
        $this->assertSame(1, DB::table('billing_cheques')->count());
        $this->assertSame(0, bccomp(bcadd((string) DB::table('billing_cheque_allocations')->sum('amount'), '0', 2), '400.00', 2));
    }

    public function test_cheques_that_fit_together_all_succeed_and_never_exceed_what_is_owed(): void
    {
        $outcomes = $this->race([
            $this->recordJob('B-1', '200.00'), $this->recordJob('B-2', '200.00'), $this->recordJob('B-3', '200.00'), $this->recordJob('B-4', '200.00'),
        ]);

        $this->assertSame(2, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(2, collect($outcomes)->where('class', 'ChequeAllocationExceedsOwedException')->count(), json_encode($outcomes));
        $allocated = DB::table('billing_cheque_allocations')->sum('amount');
        $this->assertSame(0, bccomp(bcadd((string) $allocated, '0', 2), '400.00', 2));
        $this->assertLessThanOrEqual(0, bccomp((string) $allocated, '500.00', 2), 'never more than the P500.00 that was billed');
    }

    public function test_the_same_cheque_entered_by_two_requests_at_once_is_recorded_once(): void
    {
        $outcomes = $this->race([$this->recordJob('C-1', '100.00'), $this->recordJob('C-1', '100.00')]);

        $this->assertSame(['ChequeDuplicateException', 'success'], collect($outcomes)->map(fn ($o) => $o['class'] ?? $o['outcome'])->sort()->values()->all(), json_encode($outcomes));
        $this->assertSame(1, DB::table('billing_cheques')->count());
    }

    public function test_two_returns_of_one_cheque_at_once_return_it_once(): void
    {
        $recorded = $this->race([$this->recordJob('D-1', '300.00')]);
        $chequeId = $recorded[0]['cheque_id'];
        $return = ['action' => 'return', 'user_id' => $this->managerId, 'cheque_id' => $chequeId, 'returned_on' => now()->toDateString(), 'reason' => 'Insufficient funds'];

        $outcomes = $this->race([$return, $return, $return]);

        $this->assertSame(1, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(2, collect($outcomes)->where('class', 'ChequeAlreadyReturnedException')->count(), json_encode($outcomes));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_CHEQUE_RETURNED')->count());
    }

    public function test_a_return_and_a_new_cheque_for_the_freed_room_do_not_deadlock(): void
    {
        $first = $this->race([$this->recordJob('E-1', '500.00')]);
        $outcomes = $this->race([
            ['action' => 'return', 'user_id' => $this->managerId, 'cheque_id' => $first[0]['cheque_id'], 'returned_on' => now()->toDateString(), 'reason' => 'Account closed'],
            $this->recordJob('E-2', '500.00'),
        ]);

        // Either order is legal: the new cheque is refused (nothing freed yet) or recorded (the return came first); no deadlock or crash.
        foreach ($outcomes as $outcome) {
            $this->assertContains($outcome['outcome'] === 'success' ? 'success' : $outcome['class'], ['success', 'ChequeAllocationExceedsOwedException'], json_encode($outcomes));
        }
        $this->assertSame('RETURNED', DB::table('billing_cheques')->where('cheque_number', 'E-1')->value('status'));
    }
}

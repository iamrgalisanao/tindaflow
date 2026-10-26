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
 * ADR-016 slice 4.3, BILL-008 / BILL-009: a cheque and a write-off (or two write-offs) cannot both claim the same owed money, and a write-off
 * is reversed once, even when the requests race (they take the same billing party lock). Real OS processes with their own connections are released together (the allocation limit is a
 * sum across rows, which no database constraint can express, so this test is the proof). Like the other concurrency tests this does
 * not extend PostgresSchemaTestCase: its fixtures are committed and visible to the workers.
 */
class BillingWriteOffConcurrencyTest extends TestCase
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

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_writeoff_race_'.Str::random(8);
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

    /** @return array<string, mixed> */
    private function writeOffJob(string $amount, ?string $requestId = null): array
    {
        return ['action' => 'write_off', 'user_id' => $this->managerId, 'write_off' => ['period_start' => '2026-10-01', 'amount' => $amount, 'reason' => 'TSC will not pay', 'request_id' => $requestId]];
    }

    private function written(): string
    {
        return bcadd((string) DB::table('billing_write_offs')->where('kind', 'WRITE_OFF')->sum('amount'), '0', 2);
    }

    private function allocated(): string
    {
        return bcadd((string) DB::table('billing_cheque_allocations')->sum('amount'), '0', 2);
    }

    private function assertNeverMoreThanBilled(): void
    {
        $owed = bcsub(bcsub('500.00', $this->allocated(), 2), bcsub($this->written(), bcadd((string) DB::table('billing_write_offs')->where('kind', 'REVERSAL')->sum('amount'), '0', 2), 2), 2);
        $this->assertGreaterThanOrEqual(0, bccomp($owed, '0', 2), "cheques and write-offs together claimed more than the P500.00 billed (owed would be {$owed})");
    }

    public function test_a_cheque_and_a_write_off_racing_for_the_same_owed_money_cannot_both_win(): void
    {
        $outcomes = $this->race([$this->recordJob('W-1', '400.00'), $this->writeOffJob('400.00')]);

        $this->assertSame(1, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $failed = collect($outcomes)->firstWhere('outcome', 'exception');
        $this->assertContains($failed['class'], ['ChequeAllocationExceedsOwedException', 'WriteOffExceedsOwedException'], json_encode($outcomes));
        $this->assertSame(1, DB::table('billing_cheques')->count() + DB::table('billing_write_offs')->count());
        $this->assertNeverMoreThanBilled();
    }

    public function test_a_cheque_and_a_write_off_that_fit_together_both_succeed(): void
    {
        $outcomes = $this->race([$this->recordJob('W-2', '300.00'), $this->writeOffJob('200.00')]);

        $this->assertSame(2, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(['300.00', '200.00'], [$this->allocated(), $this->written()]);
        $this->assertNeverMoreThanBilled();
    }

    public function test_several_write_offs_racing_never_write_off_more_than_is_owed(): void
    {
        $outcomes = $this->race([$this->writeOffJob('200.00'), $this->writeOffJob('200.00'), $this->writeOffJob('200.00'), $this->writeOffJob('200.00')]);

        $this->assertSame(2, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(2, collect($outcomes)->where('class', 'WriteOffExceedsOwedException')->count(), json_encode($outcomes));
        $this->assertSame('400.00', $this->written());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->count());
    }

    public function test_the_same_request_sent_twice_at_once_writes_off_once(): void
    {
        $id = (string) Str::uuid();

        $outcomes = $this->race([$this->writeOffJob('100.00', $id), $this->writeOffJob('100.00', $id)]);

        $this->assertSame(2, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(1, DB::table('billing_write_offs')->count());
        $this->assertSame($outcomes[0]['write_off_id'], $outcomes[1]['write_off_id']);
        $this->assertSame('100.00', $this->written());
    }

    public function test_two_reversals_of_one_write_off_at_once_reverse_it_once(): void
    {
        $made = $this->race([$this->writeOffJob('300.00')]);
        $reverse = ['action' => 'reverse_write_off', 'user_id' => $this->managerId, 'write_off_id' => $made[0]['write_off_id'], 'reason' => 'Written off by mistake'];

        $outcomes = $this->race([$reverse, $reverse, $reverse]);

        $this->assertSame(1, collect($outcomes)->where('outcome', 'success')->count(), json_encode($outcomes));
        $this->assertSame(2, collect($outcomes)->where('class', 'WriteOffAlreadyReversedException')->count(), json_encode($outcomes));
        $this->assertSame(1, DB::table('billing_write_offs')->where('kind', 'REVERSAL')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_REVERSED')->count());
    }

    public function test_a_reversal_and_a_cheque_for_the_freed_room_do_not_deadlock(): void
    {
        $made = $this->race([$this->writeOffJob('500.00')]);
        $outcomes = $this->race([
            ['action' => 'reverse_write_off', 'user_id' => $this->managerId, 'write_off_id' => $made[0]['write_off_id'], 'reason' => 'Written off by mistake'],
            $this->recordJob('W-3', '500.00'),
        ]);

        // Either order is legal: the cheque is refused (nothing freed yet) or recorded (the reversal came first); no deadlock or crash.
        foreach ($outcomes as $outcome) {
            $this->assertContains($outcome['outcome'] === 'success' ? 'success' : $outcome['class'], ['success', 'ChequeAllocationExceedsOwedException'], json_encode($outcomes));
        }
        $this->assertSame(1, DB::table('billing_write_offs')->where('kind', 'REVERSAL')->count());
        $this->assertNeverMoreThanBilled();
    }
}

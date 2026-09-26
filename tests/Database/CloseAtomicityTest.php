<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\XReading;
use App\Models\ZReading;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * invariants.md #42 (one Z-reading per fiscal day, created atomically with the close), #38 (a variance is recorded and never
 * corrected away) and #41 (readings are append-only): a failure at any step of a close leaves nothing behind and the same key
 * then succeeds once; and once a shift is closed, nothing that happens later can change what it recorded.
 */
class CloseAtomicityTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @param  array<string, mixed>  $w */
    private function closeTheShift(array $w, string $declared): TestResponse
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => $declared], $this->key());
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, string>  $key
     */
    private function closeTheDay(array $w, array $key): TestResponse
    {
        return $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $key);
    }

    /** @return array<string, mixed> the closed shift's own columns, as stored (raw, so timestamps compare as text) */
    private function whatTheShiftRecorded(string $shiftId): array
    {
        return (array) DB::table('shifts')->where('id', $shiftId)
            ->select(['expected_cash', 'declared_cash', 'variance', 'cash_sales', 'non_cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total', 'closed_at', 'status'])
            ->first();
    }

    /** Runs $request with the named Eloquent event made to throw, then removes the listener. */
    private function failingAt(string $event, string $model, callable $request): TestResponse
    {
        Event::listen("eloquent.{$event}: {$model}", fn () => throw new \RuntimeException("failure injected at {$event} {$model}"));
        try {
            return $request();
        } finally {
            Event::forget("eloquent.{$event}: {$model}");
        }
    }

    // ---------------------------------------------------------------- #42 the Z close is atomic

    public function test_a_failure_at_any_step_of_the_day_close_leaves_the_day_open_and_the_same_key_then_closes_it_once(): void
    {
        $w = $this->world();
        $this->closeTheShift($w, '1000.00')->assertOk();
        $key = $this->key();
        $dayId = $w['cashierShift']->fiscal_day_id;

        foreach ([['creating', ZReading::class], ['updating', FiscalDay::class], ['creating', AuditEvent::class], ['creating', ElectronicJournalEntry::class]] as [$event, $model]) {
            $failed = $this->failingAt($event, $model, fn () => $this->closeTheDay($w, $key));

            $failed->assertStatus(500);
            $this->assertSame('OPEN', FiscalDay::find($dayId)->status, "the day must stay OPEN after a failure at {$event} {$model}");
            $this->assertNull(FiscalDay::find($dayId)->closed_at);
            $this->assertSame(0, ZReading::count(), "no Z-reading may survive a failure at {$event} {$model}");
            $this->assertSame(0, AuditEvent::where('event_type', 'Z_READING_GENERATED')->count());
            $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'Z_READING')->count());
            $this->assertSame(0, DB::table('idempotency_records')->where('idempotency_key', $key['Idempotency-Key'])->count(), 'the key must stay reusable');
        }

        $closed = $this->closeTheDay($w, $key);
        $closed->assertOk();
        $closed->assertJson(['fiscal_day' => ['status' => 'CLOSED']]);
        $this->assertSame(1, ZReading::where('fiscal_day_id', $dayId)->count(), 'exactly one Z-reading for the day (#42)');
        $this->assertSame(1, $closed->json('z_reading.totals_snapshot.z_counter'));
        $this->assertSame(1, ElectronicJournalEntry::where('event_type', 'Z_READING')->count());
    }

    public function test_a_day_that_is_closed_can_never_get_a_second_z_reading(): void
    {
        $w = $this->world();
        $this->closeTheShift($w, '1000.00')->assertOk();
        $this->closeTheDay($w, $this->key())->assertOk();
        $reading = ZReading::where('fiscal_day_id', $w['cashierShift']->fiscal_day_id)->sole();

        $this->closeTheDay($w, $this->key())->assertStatus(409)->assertJson(['error' => ['code' => 'FISCAL_DAY_CLOSED']]);

        $this->assertSame(1, ZReading::count());
        $this->assertEquals($reading->fresh()->totals_snapshot, $reading->totals_snapshot, 'the stored reading is untouched');
        $this->assertNotNull(FiscalDay::find($w['cashierShift']->fiscal_day_id)->closed_at);
    }

    public function test_a_failure_at_any_step_of_the_shift_close_leaves_the_shift_open_and_the_same_key_then_closes_it_once(): void
    {
        $w = $this->world();
        $shiftId = $w['cashierShift']->id;
        $key = $this->key();
        $close = fn () => $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1000.00'], $key);

        foreach ([['creating', XReading::class], ['updating', Shift::class], ['creating', AuditEvent::class], ['creating', ElectronicJournalEntry::class]] as [$event, $model]) {
            $this->failingAt($event, $model, $close)->assertStatus(500);

            $this->assertSame('OPEN', Shift::find($shiftId)->status, "the shift must stay OPEN after a failure at {$event} {$model}");
            $this->assertNull(Shift::find($shiftId)->declared_cash);
            $this->assertSame(0, XReading::where('shift_id', $shiftId)->where('is_closing_reading', true)->count());
            $this->assertSame(0, AuditEvent::where('event_type', 'SHIFT_CLOSED')->count());
            $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'SHIFT_CLOSED')->count());
        }

        $close()->assertOk();
        $this->assertSame('CLOSED', Shift::find($shiftId)->status);
        $this->assertSame(1, XReading::where('shift_id', $shiftId)->where('is_closing_reading', true)->count());
    }

    // ---------------------------------------------------------------- #38/#41 what a close recorded stays recorded

    public function test_a_closed_shifts_variance_cannot_be_corrected_away_by_closing_again_or_by_anything_later(): void
    {
        $w = $this->world();
        $sale = $this->ring($w); // 200.00 in cash, in the cashier's shift

        // The drawer is counted 15.00 short.
        $expected = bcadd((string) $w['cashierShift']->opening_cash, '200.00', 2);
        $declared = bcsub($expected, '15.00', 2);
        $closed = $this->closeTheShift($w, $declared);
        $closed->assertOk();
        $this->assertSame('-15.00', $closed->json('shift.variance'));
        $recorded = $this->whatTheShiftRecorded($w['cashierShift']->id);
        $closingReading = XReading::where('shift_id', $w['cashierShift']->id)->where('is_closing_reading', true)->sole();

        // 1. Closing it again, with a "better" count, is refused and changes nothing.
        $this->asUser($w['cashier'], $w['enroll1'])
            ->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => $expected], $this->key())
            ->assertStatus(409);

        // 2. A refund of that very sale, taken later in a manager's own shift, is attributed there, not to the closed shift.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Customer came back',
        ], $this->key())->assertStatus(201);

        // 3. There is no route that edits or removes a closed shift or a reading.
        foreach (['patch', 'put', 'delete'] as $verb) {
            $this->assertContains($this->asUser($w['admin'], $w['enroll1'])->{$verb.'Json'}("/api/v1/shifts/{$w['cashierShift']->id}")->status(), [404, 405]);
            $this->assertContains($this->asUser($w['admin'], $w['enroll1'])->{$verb.'Json'}("/api/v1/x-readings/{$closingReading->id}")->status(), [404, 405]);
        }

        $this->assertSame($recorded, $this->whatTheShiftRecorded($w['cashierShift']->id), 'nothing later changes what the closed shift recorded');
        $this->assertEquals($closingReading->totals_snapshot, $closingReading->fresh()->totals_snapshot, 'the closing X-reading is append-only (#41)');
        $this->assertSame(1, XReading::where('shift_id', $w['cashierShift']->id)->where('is_closing_reading', true)->count());
    }
}

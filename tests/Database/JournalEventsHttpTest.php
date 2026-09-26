<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * invariants.md #49/#50: every fiscally journalable event writes exactly one electronic-journal entry that points at the
 * record it describes and at its audit event. Sales, voids, refunds, reprints, stock adjustments and shift opens are
 * proved in their own suites; this covers the cash, reading and closing events, driven through the real endpoints.
 */
class JournalEventsHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    public function test_cash_movements_readings_and_closings_each_write_one_journal_entry_that_points_at_their_record(): void
    {
        $w = $this->world();
        $shiftId = $w['cashierShift']->id;
        $asCashier = fn () => $this->asUser($w['cashier'], $w['enroll1']);

        $cashIn = $asCashier()->postJson("/api/v1/shifts/{$shiftId}/cash-movements", ['type' => 'CASH_IN', 'amount' => '200.00', 'reason' => 'Float'], $this->key());
        $cashIn->assertStatus(201);
        $cashOut = $asCashier()->postJson("/api/v1/shifts/{$shiftId}/cash-movements", ['type' => 'CASH_OUT', 'amount' => '50.00', 'reason' => 'Supplies'], $this->key());
        $cashOut->assertStatus(201);
        $xReading = $asCashier()->postJson("/api/v1/shifts/{$shiftId}/x-readings", [], $this->key());
        $xReading->assertSuccessful();
        $close = $asCashier()->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1000.00'], $this->key());
        $close->assertOk();
        $zReading = $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key());
        $zReading->assertOk();

        $expected = [
            'CASH_IN' => ['cash_movement', $cashIn->json('id')],
            'CASH_OUT' => ['cash_movement', $cashOut->json('id')],
            'X_READING' => ['x_reading', $xReading->json('id')],
            'SHIFT_CLOSED' => ['x_reading', $close->json('x_reading.id')],
            'Z_READING' => ['z_reading', $zReading->json('z_reading.id')],
        ];

        foreach ($expected as $eventType => [$sourceType, $sourceId]) {
            $entries = ElectronicJournalEntry::where('event_type', $eventType)->get();

            $this->assertCount(1, $entries, "exactly one {$eventType} journal entry");
            $this->assertSame($sourceType, $entries[0]->source_type, "{$eventType} source type");
            $this->assertNotNull($sourceId, "{$eventType}: the response must expose the record the journal points at");
            $this->assertSame($sourceId, $entries[0]->source_id, "{$eventType} points at the record it describes");
            $this->assertNotNull($entries[0]->audit_event_id, "{$eventType} links its audit event");
            $this->assertNotNull(AuditEvent::find($entries[0]->audit_event_id), "{$eventType}'s audit event exists");
            $this->assertSame($w['storeId'], $entries[0]->store_id);
        }
    }
}

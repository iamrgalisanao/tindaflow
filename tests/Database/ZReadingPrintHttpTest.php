<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Store;
use App\Models\ZReading;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Z-reading paper: the plain original (a read, records nothing) and the recorded, REPRINT-marked copy. Both come from the stored
 * snapshot; nothing about the reading, the fiscal day or any counter changes.
 */
class ZReadingPrintHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return array{0: array<string, mixed>, 1: ZReading} a world whose business day has been closed through the real endpoints */
    private function closedDay(): array
    {
        $w = $this->world();
        $this->ring($w);
        $shiftId = $w['cashierShift']->id;
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();
        $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();

        return [$w, ZReading::where('fiscal_day_id', $w['cashierShift']->fiscal_day_id)->sole()];
    }

    public function test_the_original_is_the_plain_document_from_the_stored_snapshot_and_records_nothing(): void
    {
        [$w, $reading] = $this->closedDay();
        $before = AuditEvent::count();

        $response = $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/document");

        $response->assertOk()->assertJson(['is_reprint' => false]);
        $html = $response->json('render_html');
        $this->assertStringContainsString('Z-READING', $html);
        $this->assertStringContainsString('<title>Z-Reading '.$reading->totals_snapshot['z_counter'].'</title>', $html);
        $this->assertStringContainsString('PHP '.number_format((float) $reading->totals_snapshot['gross_sales'], 2), $html);
        $this->assertStringContainsString($reading->terminal->terminal_code, $html);
        $this->assertStringNotContainsString('REPRINT', $html);
        $this->assertSame($before, AuditEvent::count(), 'reading the original records nothing');
    }

    public function test_a_reprint_is_marked_and_recorded_with_who_asked_and_changes_nothing_else(): void
    {
        [$w, $reading] = $this->closedDay();
        $snapshot = $reading->totals_snapshot;

        $response = $this->asUser($w['manager'])->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/reprints");

        $response->assertStatus(201)->assertJson(['is_reprint' => true, 'requested_by' => $w['manager']->id]);
        $this->assertStringContainsString('REPRINT &mdash; COPY', $response->json('render_html'));
        $event = AuditEvent::where('event_type', 'Z_READING_REPRINTED')->sole();
        $this->assertSame($response->json('reprint_event_id'), $event->id);
        $this->assertSame($w['manager']->id, $event->actor_user_id);
        $this->assertSame($reading->fiscal_day_id, $event->entity_id);
        $this->assertSame($snapshot['z_counter'], $event->after_metadata['z_counter']);
        $this->assertEquals($snapshot, $reading->refresh()->totals_snapshot, 'the reading is untouched');
        $this->assertSame(1, ZReading::where('fiscal_day_id', $reading->fiscal_day_id)->count());
    }

    public function test_every_reprint_is_recorded_on_its_own(): void
    {
        [$w, $reading] = $this->closedDay();

        $this->asUser($w['admin'])->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/reprints")->assertStatus(201);
        $this->asUser($w['admin'])->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/reprints")->assertStatus(201);

        $this->assertSame(2, AuditEvent::where('event_type', 'Z_READING_REPRINTED')->count());
    }

    public function test_the_figures_are_the_stored_ones_even_if_the_ledger_has_moved_since(): void
    {
        [$w, $reading] = $this->closedDay();
        $stored = $reading->totals_snapshot['gross_sales'];
        Sale::query()->update(['grand_total' => '1.00']);

        $html = $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/document")->json('render_html');

        $this->assertStringContainsString('PHP '.number_format((float) $stored, 2), $html, 'never recalculated');
    }

    public function test_the_shop_name_is_escaped(): void
    {
        [$w, $reading] = $this->closedDay();
        Store::where('id', $w['storeId'])->update(['name' => '<script>alert(1)</script> Store']);

        $html = $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/document")->json('render_html');

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Store', $html);
    }

    public function test_another_stores_day_and_a_day_with_no_reading_are_not_found(): void
    {
        [$w, $reading] = $this->closedDay();
        $other = $this->world();
        $openDay = Shift::find($other['cashierShift']->id)->fiscal_day_id; // open: no Z-reading yet

        foreach ([$openDay, $reading->fiscal_day_id] as $dayId) {
            $as = $this->asUser($other['admin']);
            $as->getJson("/api/v1/fiscal-days/{$dayId}/z-reading/document")->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
            $as->postJson("/api/v1/fiscal-days/{$dayId}/z-reading/reprints")->assertStatus(404);
        }
        $this->assertSame(0, AuditEvent::where('event_type', 'Z_READING_REPRINTED')->count());
    }

    public function test_it_needs_a_session(): void
    {
        $id = (string) Str::uuid();

        $this->getJson("/api/v1/fiscal-days/{$id}/z-reading/document")->assertStatus(401);
        $this->postJson("/api/v1/fiscal-days/{$id}/z-reading/reprints")->assertStatus(401);
    }
}

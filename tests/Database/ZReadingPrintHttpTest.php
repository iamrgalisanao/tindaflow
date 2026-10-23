<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Store;
use App\Models\ZReading;
use App\Models\ZReadingPrint;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Z-reading paper, with the SERVER deciding which printout is the original: the first print of a reading is the plain original (recorded),
 * every later one a copy marked REPRINT -- COPY (recorded). Both come from the stored snapshot; nothing about the reading, the fiscal day
 * or any counter changes.
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

    private function print(array $w, ZReading $reading, string $as = 'admin')
    {
        return $this->asUser($w[$as])->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/prints");
    }

    public function test_the_first_print_is_the_plain_original_and_is_recorded_as_such(): void
    {
        [$w, $reading] = $this->closedDay();

        $response = $this->print($w, $reading);

        $response->assertStatus(201)->assertJson(['is_reprint' => false, 'requested_by' => $w['admin']->id]);
        $html = $response->json('render_html');
        $this->assertStringContainsString('Z-READING', $html);
        $this->assertStringContainsString('<title>Z-Reading '.$reading->totals_snapshot['z_counter'].'</title>', $html);
        $this->assertStringContainsString('PHP '.number_format((float) $reading->totals_snapshot['gross_sales'], 2), $html);
        $this->assertStringContainsString($reading->terminal->terminal_code, $html);
        $this->assertStringNotContainsString('REPRINT', $html);
        $row = ZReadingPrint::sole();
        $this->assertSame($reading->id, $row->z_reading_id);
        $this->assertSame($w['admin']->id, $row->printed_by);
        $event = AuditEvent::where('event_type', 'Z_READING_PRINTED')->sole();
        $this->assertSame($w['admin']->id, $event->actor_user_id);
        $this->assertSame(0, AuditEvent::where('event_type', 'Z_READING_REPRINTED')->count());
    }

    public function test_every_later_print_is_a_marked_copy_even_from_someone_else(): void
    {
        [$w, $reading] = $this->closedDay();
        $this->print($w, $reading, 'manager')->assertStatus(201)->assertJson(['is_reprint' => false]);

        $copy = $this->print($w, $reading, 'admin');
        $again = $this->print($w, $reading, 'manager');

        foreach ([$copy, $again] as $response) {
            $response->assertStatus(201)->assertJson(['is_reprint' => true]);
            $this->assertStringContainsString('REPRINT &mdash; COPY', $response->json('render_html'));
        }
        $this->assertSame(1, ZReadingPrint::count(), 'there is exactly one original');
        $this->assertSame($w['manager']->id, ZReadingPrint::sole()->printed_by, 'the first printer stays the original');
        $this->assertSame(1, AuditEvent::where('event_type', 'Z_READING_PRINTED')->count());
        $this->assertSame(2, AuditEvent::where('event_type', 'Z_READING_REPRINTED')->count());
    }

    public function test_an_original_claimed_a_moment_earlier_makes_the_next_print_a_copy_without_a_second_original(): void
    {
        [$w, $reading] = $this->closedDay();
        // Another request won the race for the original between this one's check and its insert: the unique constraint decides.
        ZReadingPrint::create(['store_id' => $w['storeId'], 'z_reading_id' => $reading->id, 'printed_by' => $w['manager']->id]);

        $response = $this->print($w, $reading);

        $response->assertStatus(201)->assertJson(['is_reprint' => true]);
        $this->assertSame(1, ZReadingPrint::count());
    }

    public function test_printing_changes_nothing_about_the_reading_and_the_resource_says_when_the_original_was_printed(): void
    {
        [$w, $reading] = $this->closedDay();
        $snapshot = $reading->totals_snapshot;
        $this->assertNull($this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading")->json('original_printed_at'));

        $this->print($w, $reading);
        $this->print($w, $reading);

        $this->assertEquals($snapshot, $reading->refresh()->totals_snapshot);
        $this->assertSame(1, ZReading::where('fiscal_day_id', $reading->fiscal_day_id)->count());
        $read = $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading");
        $this->assertNotNull($read->json('original_printed_at'));
        $this->assertSame($w['admin']->id, $read->json('original_printed_by'));
    }

    public function test_the_figures_are_the_stored_ones_even_if_the_ledger_has_moved_since(): void
    {
        [$w, $reading] = $this->closedDay();
        $stored = $reading->totals_snapshot['gross_sales'];
        Sale::query()->update(['grand_total' => '1.00']);

        $html = $this->print($w, $reading)->json('render_html');

        $this->assertStringContainsString('PHP '.number_format((float) $stored, 2), $html, 'never recalculated');
    }

    public function test_the_shop_name_is_escaped(): void
    {
        [$w, $reading] = $this->closedDay();
        Store::where('id', $w['storeId'])->update(['name' => '<script>alert(1)</script> Store']);

        $html = $this->print($w, $reading)->json('render_html');

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Store', $html);
    }

    public function test_another_stores_day_and_a_day_with_no_reading_are_not_found_and_leave_no_trace(): void
    {
        [, $reading] = $this->closedDay();
        $other = $this->world();
        $openDay = Shift::find($other['cashierShift']->id)->fiscal_day_id; // open: no Z-reading yet

        foreach ([$openDay, $reading->fiscal_day_id] as $dayId) {
            $this->asUser($other['admin'])->postJson("/api/v1/fiscal-days/{$dayId}/z-reading/prints")
                ->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
        }
        $this->assertSame(0, ZReadingPrint::count());
        $this->assertSame(0, AuditEvent::whereIn('event_type', ['Z_READING_PRINTED', 'Z_READING_REPRINTED'])->count());
    }

    public function test_a_cashier_cannot_print_the_days_totals(): void
    {
        [$w, $reading] = $this->closedDay();

        $this->print($w, $reading, 'cashier')->assertStatus(403);

        $this->assertSame(0, ZReadingPrint::count(), 'a refused request must not become the original');
        $this->assertSame(0, AuditEvent::whereIn('event_type', ['Z_READING_PRINTED', 'Z_READING_REPRINTED'])->count());
    }

    public function test_prints_are_rate_limited_so_they_cannot_flood_the_audit_log(): void
    {
        [$w, $reading] = $this->closedDay();
        $as = $this->asUser($w['admin']);

        foreach (range(1, 30) as $n) {
            $as->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/prints")->assertStatus(201);
        }
        $as->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/prints")->assertStatus(429);

        $this->assertSame(30, AuditEvent::whereIn('event_type', ['Z_READING_PRINTED', 'Z_READING_REPRINTED'])->count());
    }

    public function test_it_needs_a_session(): void
    {
        $id = (string) Str::uuid();

        $this->postJson("/api/v1/fiscal-days/{$id}/z-reading/prints")->assertStatus(401);
    }
}

<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml shiftList/shiftGet/shiftXReadingList and fiscalDayList/fiscalDayGet/fiscalDayZReadingGet: the
 * session-only, store-scoped history reads. A shift or fiscal day of another store is SHIFT_NOT_FOUND /
 * FISCAL_DAY_NOT_FOUND, never confirmed to exist.
 */
class ShiftFiscalDayHistoryHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** A closed shift and fiscal day on $terminal, opened at a known moment and business date. */
    private function closedDay(array $w, string $businessDate, string $openedAt, ?User $cashier = null): array
    {
        $terminal = $w['t1'];
        $fiscalDay = FiscalDay::factory()->closed()->create([
            'store_id' => $w['storeId'], 'terminal_id' => $terminal->id, 'business_date' => $businessDate, 'opened_at' => $openedAt,
        ]);
        $shift = Shift::factory()->closed()->create([
            'terminal_id' => $terminal->id, 'fiscal_day_id' => $fiscalDay->id, 'cashier_id' => ($cashier ?? $w['cashier'])->id, 'opened_at' => $openedAt,
        ]);

        return [$fiscalDay, $shift];
    }

    /** Rings a sale, then closes the cashier's shift and the fiscal day through the real endpoints. */
    private function closeTheDay(array $w): void
    {
        $this->ring($w);
        $shiftId = $w['cashierShift']->id;

        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();
        $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
    }

    // ---------------------------------------------------------------- shiftList

    public function test_the_shift_list_is_the_actors_store_newest_first_for_any_signed_in_user_without_a_terminal(): void
    {
        $w = $this->world();
        [, $older] = $this->closedDay($w, '2026-03-09', '2026-03-09 08:00:00');
        [, $newer] = $this->closedDay($w, '2026-03-10', '2026-03-10 08:00:00');
        $other = $this->world();

        $response = $this->asUser($w['cashier'])->getJson('/api/v1/shifts');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$w['cashierShift']->id, $w['managerShift']->id, $older->id, $newer->id], $ids);
        $this->assertNotContains($other['cashierShift']->id, $ids);
        $this->assertSame([$newer->id, $older->id], array_values(array_filter($ids, fn ($id) => in_array($id, [$newer->id, $older->id], true))), 'newest opened first');
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 4, 'last_page' => 1], $response->json('meta'));
        $this->assertSame(['id', 'terminal_id', 'fiscal_day_id', 'cashier_id', 'opening_cash', 'opened_at', 'status', 'expected_cash', 'declared_cash', 'variance', 'cash_sales', 'non_cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total', 'closed_at'], array_keys($response->json('data.0')));
    }

    public function test_the_shift_list_filters_by_terminal_cashier_fiscal_day_and_opening_date(): void
    {
        $w = $this->world();
        $otherCashier = User::factory()->create(['store_id' => $w['storeId']]);
        [$dayA, $shiftA] = $this->closedDay($w, '2026-03-09', '2026-03-09 23:30:00');
        [$dayB, $shiftB] = $this->closedDay($w, '2026-03-10', '2026-03-10 08:00:00', $otherCashier);
        $actor = $this->asUser($w['manager']);

        $ids = fn (string $query) => array_column($actor->getJson('/api/v1/shifts?'.$query)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$w['managerShift']->id], $ids('terminal_id='.$w['t2']->id));
        $this->assertEqualsCanonicalizing([$shiftB->id], $ids('cashier_id='.$otherCashier->id));
        $this->assertEqualsCanonicalizing([$shiftA->id], $ids('fiscal_day_id='.$dayA->id));
        $this->assertEqualsCanonicalizing([$shiftA->id], $ids('from=2026-03-09&to=2026-03-09'), 'inclusive of the whole day');
        $this->assertEqualsCanonicalizing([$shiftA->id, $shiftB->id], $ids('from=2026-03-09&to=2026-03-10&terminal_id='.$w['t1']->id));
        $this->assertEqualsCanonicalizing([$shiftB->id, $w['cashierShift']->id], $ids('from=2026-03-10&terminal_id='.$w['t1']->id), 'the shift opened today is on or after that date too');
        $this->assertNotEmpty($dayB);
    }

    public function test_a_filter_that_cannot_match_anything_yields_an_empty_page_rather_than_widening_the_list(): void
    {
        $w = $this->world();
        $actor = $this->asUser($w['manager']);

        foreach (['terminal_id=not-a-uuid', 'cashier_id=nope', 'fiscal_day_id=1'] as $query) {
            $actor->getJson('/api/v1/shifts?'.$query)->assertOk()->assertJson(['data' => [], 'meta' => ['total' => 0]]);
        }
        $this->assertNotEmpty($actor->getJson('/api/v1/shifts?from=garbage')->json('data'), 'a malformed date is ignored');
    }

    public function test_the_shift_list_paginates(): void
    {
        $w = $this->world();
        foreach (range(1, 4) as $day) {
            $this->closedDay($w, "2026-02-0{$day}", "2026-02-0{$day} 08:00:00");
        }

        $response = $this->asUser($w['manager'])->getJson('/api/v1/shifts?per_page=2&page=2');

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 6, 'last_page' => 3], $response->json('meta'));
    }

    // ---------------------------------------------------------------- shiftGet

    public function test_a_shift_can_be_read_with_its_closing_figures(): void
    {
        $w = $this->world();
        $this->closeTheDay($w);

        $response = $this->asUser($w['manager'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}");

        $response->assertOk();
        $response->assertJson([
            'id' => $w['cashierShift']->id, 'terminal_id' => $w['t1']->id, 'cashier_id' => $w['cashier']->id, 'status' => 'CLOSED',
            'declared_cash' => '1200.00',
        ]);
        $this->assertNotNull($response->json('closed_at'));
        $this->assertNotNull($response->json('variance'));
    }

    public function test_an_unknown_or_foreign_shift_is_shift_not_found(): void
    {
        $w = $this->world();
        $other = $this->world();
        $actor = $this->asUser($w['manager']);

        $actor->getJson("/api/v1/shifts/{$other['cashierShift']->id}")->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
        $actor->getJson('/api/v1/shifts/'.Str::uuid())->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
    }

    public function test_the_history_routes_do_not_shadow_the_current_shift(): void
    {
        $w = $this->world();

        $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/shifts/current')->assertOk()->assertJson(['id' => $w['cashierShift']->id]);
        $this->asUser($w['cashier'])->getJson('/api/v1/shifts/current')->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    // ---------------------------------------------------------------- shiftXReadingList

    public function test_x_readings_are_listed_for_a_shift_from_a_plain_browser_session(): void
    {
        $w = $this->world();
        $this->closeTheDay($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/shifts/{$w['managerShift']->id}/x-readings")->assertStatus(201);

        // The closing reading was made at T1; the admin's browser is enrolled nowhere and reads it anyway.
        $response = $this->asUser($w['admin'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings");

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertTrue($response->json('0.is_closing_reading'));
        $this->assertSame($w['cashierShift']->id, $response->json('0.shift_id'));
        $this->assertNotNull($response->json('0.totals_snapshot.expected_cash'));
    }

    public function test_x_readings_of_a_missing_or_foreign_shift_are_shift_not_found_not_an_empty_list(): void
    {
        $w = $this->world();
        $other = $this->world();
        $actor = $this->asUser($w['admin']);

        $actor->getJson("/api/v1/shifts/{$other['cashierShift']->id}/x-readings")->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
        $actor->getJson('/api/v1/shifts/'.Str::uuid().'/x-readings')->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
    }

    public function test_a_shift_with_no_reading_yet_lists_an_empty_array(): void
    {
        $w = $this->world();

        $this->asUser($w['admin'])->getJson("/api/v1/shifts/{$w['managerShift']->id}/x-readings")->assertOk()->assertExactJson([]);
    }

    // ---------------------------------------------------------------- fiscalDayList / fiscalDayGet

    public function test_the_fiscal_day_list_is_the_actors_store_most_recent_business_date_first(): void
    {
        $w = $this->world();
        [$older] = $this->closedDay($w, '2026-03-09', '2026-03-09 08:00:00');
        [$newer] = $this->closedDay($w, '2026-03-10', '2026-03-10 08:00:00');
        $other = $this->world();

        $response = $this->asUser($w['cashier'])->getJson('/api/v1/fiscal-days');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$w['cashierShift']->fiscal_day_id, $w['managerShift']->fiscal_day_id, $older->id, $newer->id], $ids);
        $this->assertNotContains($other['cashierShift']->fiscal_day_id, $ids);
        $this->assertSame([$newer->id, $older->id], array_values(array_filter($ids, fn ($id) => in_array($id, [$newer->id, $older->id], true))));
        $this->assertSame(['id', 'store_id', 'terminal_id', 'business_date', 'opened_at', 'closed_at', 'status'], array_keys($response->json('data.0')));
    }

    public function test_the_fiscal_day_list_filters_by_terminal_and_business_date(): void
    {
        $w = $this->world();
        [$march9] = $this->closedDay($w, '2026-03-09', '2026-03-09 08:00:00');
        [$march10] = $this->closedDay($w, '2026-03-10', '2026-03-10 08:00:00');
        $actor = $this->asUser($w['manager']);

        $ids = fn (string $query) => array_column($actor->getJson('/api/v1/fiscal-days?'.$query)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$w['managerShift']->fiscal_day_id], $ids('terminal_id='.$w['t2']->id));
        $this->assertEqualsCanonicalizing([$march9->id], $ids('from=2026-03-09&to=2026-03-09'));
        $this->assertEqualsCanonicalizing([$march9->id, $march10->id], $ids('from=2026-03-09&to=2026-03-10&terminal_id='.$w['t1']->id));
        $this->assertSame([], $ids('terminal_id=nope'));
    }

    public function test_a_fiscal_day_can_be_read_and_a_foreign_or_unknown_one_is_fiscal_day_not_found(): void
    {
        $w = $this->world();
        $other = $this->world();
        $actor = $this->asUser($w['manager']);
        $day = $w['cashierShift']->fiscalDay;

        $actor->getJson("/api/v1/fiscal-days/{$day->id}")->assertOk()->assertJson([
            'id' => $day->id, 'store_id' => $w['storeId'], 'terminal_id' => $w['t1']->id, 'status' => 'OPEN', 'closed_at' => null,
        ]);
        $actor->getJson("/api/v1/fiscal-days/{$other['cashierShift']->fiscal_day_id}")->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
        $actor->getJson('/api/v1/fiscal-days/'.Str::uuid())->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
    }

    // ---------------------------------------------------------------- fiscalDayZReadingGet

    public function test_a_z_reading_is_readable_from_a_plain_browser_session_once_the_day_is_closed(): void
    {
        $w = $this->world();
        $dayId = $w['cashierShift']->fiscal_day_id;
        $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$dayId}/z-reading")->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);

        $this->closeTheDay($w);

        $response = $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$dayId}/z-reading");
        $response->assertOk()->assertJson(['fiscal_day_id' => $dayId, 'terminal_id' => $w['t1']->id]);
        $this->assertSame('200.00', $response->json('totals_snapshot.gross_sales'));
        $this->assertSame(1, $response->json('totals_snapshot.z_counter'));

        $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$dayId}")->assertOk()->assertJson(['status' => 'CLOSED']);
    }

    public function test_a_foreign_stores_z_reading_is_not_found(): void
    {
        $w = $this->world();
        $other = $this->world();
        $this->closeTheDay($other);

        $this->asUser($w['admin'])->getJson("/api/v1/fiscal-days/{$other['cashierShift']->fiscal_day_id}/z-reading")
            ->assertStatus(404)->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
    }

    // ---------------------------------------------------------------- authentication

    public function test_every_history_read_needs_a_session(): void
    {
        $id = (string) Str::uuid();

        foreach (['/shifts', "/shifts/{$id}", "/shifts/{$id}/x-readings", '/fiscal-days', "/fiscal-days/{$id}", "/fiscal-days/{$id}/z-reading"] as $path) {
            $this->getJson('/api/v1'.$path)->assertStatus(401);
        }
    }

    public function test_a_store_with_no_history_lists_nothing(): void
    {
        $lonely = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);

        $this->asUser($lonely)->getJson('/api/v1/shifts')->assertOk()->assertJson(['data' => [], 'meta' => ['total' => 0]]);
        $this->asUser($lonely)->getJson('/api/v1/fiscal-days')->assertOk()->assertJson(['data' => [], 'meta' => ['total' => 0]]);
    }
}

<?php

namespace Tests\Database;

use App\Models\Sale;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 24 decision 3: who reads the drawer. A user with REPORT_VIEW (manager, admin) reads every shift and sale of the
 * store; a cashier reads only their own, and never sees the cash figures of an interim X-reading (a blind close).
 * Another cashier's shift or sale is SHIFT_NOT_FOUND / SALE_NOT_FOUND, not 403, so it is never confirmed to exist.
 */
class CashVisibilityHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function closeCashiersShift(array $w): void
    {
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();
    }

    // ---------------------------------------------------------------- shifts

    public function test_a_cashier_lists_only_their_own_shifts_and_a_manager_lists_all(): void
    {
        $w = $this->world();

        $mine = array_column($this->asUser($w['cashier'])->getJson('/api/v1/shifts')->assertOk()->json('data'), 'id');
        $all = array_column($this->asUser($w['manager'])->getJson('/api/v1/shifts')->assertOk()->json('data'), 'id');

        $this->assertSame([$w['cashierShift']->id], $mine);
        $this->assertEqualsCanonicalizing([$w['cashierShift']->id, $w['managerShift']->id], $all);
        $this->assertSame(1, $this->asUser($w['cashier'])->getJson('/api/v1/shifts')->json('meta.total'), 'the total does not leak the others either');
        $this->assertEqualsCanonicalizing([$w['cashierShift']->id, $w['managerShift']->id], array_column($this->asUser($w['admin'])->getJson('/api/v1/shifts')->json('data'), 'id'));
    }

    public function test_a_cashier_cannot_read_someone_elses_shift_or_its_readings(): void
    {
        $w = $this->world();
        $client = $this->asUser($w['cashier']);

        $client->getJson("/api/v1/shifts/{$w['cashierShift']->id}")->assertOk();
        $client->getJson("/api/v1/shifts/{$w['managerShift']->id}")->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
        $this->asUser($w['cashier'])->getJson("/api/v1/shifts/{$w['managerShift']->id}/x-readings")->assertStatus(404)->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
        // Filtering by another cashier cannot widen it.
        $this->assertSame([], $this->asUser($w['cashier'])->getJson('/api/v1/shifts?cashier_id='.$w['manager']->id)->json('data'));
        $this->asUser($w['manager'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}")->assertOk();
    }

    public function test_a_cashier_sees_the_full_result_of_their_own_closed_shift(): void
    {
        $w = $this->world();
        $this->ring($w);
        $this->closeCashiersShift($w);

        $shift = $this->asUser($w['cashier'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}")->assertOk();

        $this->assertSame('CLOSED', $shift->json('status'));
        $this->assertSame('1200.00', $shift->json('declared_cash'));
        $this->assertNotNull($shift->json('expected_cash'));
        $this->assertNotNull($shift->json('variance'));
    }

    // ---------------------------------------------------------------- interim readings

    public function test_an_interim_reading_hides_every_cash_figure_from_a_cashier_but_not_from_a_manager(): void
    {
        $w = $this->world();
        $this->ring($w);
        $this->ring($w, method: 'GCASH');

        $blind = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings")->assertStatus(201);
        $totals = $blind->json('totals_snapshot');

        foreach (['expected_cash', 'variance', 'cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total'] as $hidden) {
            $this->assertNull($totals[$hidden], "{$hidden} must be hidden");
        }
        $this->assertArrayNotHasKey('CASH', $totals['payment_breakdown']);
        $this->assertArrayHasKey('GCASH', $totals['payment_breakdown'], 'a cashier still sees what was not cash');
        $this->assertNotNull($totals['opening_cash'], 'they know what they started with');
        $this->assertSame(2, $totals['transaction_count']);

        // The same reading, read back through the list, is just as blind for them and complete for a manager.
        $listed = $this->asUser($w['cashier'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings")->assertOk();
        $this->assertNull($listed->json('0.totals_snapshot.expected_cash'));
        $full = $this->asUser($w['manager'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings")->assertOk();
        $this->assertNotNull($full->json('0.totals_snapshot.expected_cash'));
        $this->assertNotNull($full->json('0.totals_snapshot.cash_sales'));
        $this->assertArrayHasKey('CASH', $full->json('0.totals_snapshot.payment_breakdown'));
    }

    public function test_a_managers_own_interim_reading_is_complete(): void
    {
        $w = $this->world();

        $reading = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/shifts/{$w['managerShift']->id}/x-readings")->assertStatus(201);

        $this->assertNotNull($reading->json('totals_snapshot.expected_cash'));
        $this->assertNotNull($reading->json('totals_snapshot.cash_sales'));
    }

    public function test_the_closing_reading_shows_the_cashier_everything_once_they_have_declared(): void
    {
        $w = $this->world();
        $this->ring($w);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings")->assertStatus(201);

        $closed = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();

        $this->assertTrue($closed->json('x_reading.is_closing_reading'));
        $this->assertNotNull($closed->json('x_reading.totals_snapshot.expected_cash'));
        $this->assertNotNull($closed->json('x_reading.totals_snapshot.variance'));

        $readings = $this->asUser($w['cashier'])->getJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings")->assertOk()->json();
        $this->assertCount(2, $readings);
        $this->assertNull($readings[0]['totals_snapshot']['expected_cash'], 'the earlier interim reading stays blind');
        $this->assertNotNull($readings[1]['totals_snapshot']['expected_cash'], 'the closing one is complete');
    }

    // ---------------------------------------------------------------- sales

    public function test_a_cashier_reads_only_their_own_sales_and_a_manager_reads_all(): void
    {
        $w = $this->world();
        $mine = $this->ring($w);
        $theirs = $this->ring($w);
        Sale::where('id', $theirs['id'])->update(['cashier_id' => $w['manager']->id]);

        $cashierSees = array_column($this->asUser($w['cashier'])->getJson('/api/v1/sales')->assertOk()->json('data'), 'id');
        $managerSees = array_column($this->asUser($w['manager'])->getJson('/api/v1/sales')->assertOk()->json('data'), 'id');

        $this->assertSame([$mine['id']], $cashierSees);
        $this->assertEqualsCanonicalizing([$mine['id'], $theirs['id']], $managerSees);
        $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$mine['id']}")->assertOk();
        $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$theirs['id']}")->assertStatus(404)->assertJson(['error' => ['code' => 'SALE_NOT_FOUND']]);
        $this->asUser($w['manager'])->getJson("/api/v1/sales/{$theirs['id']}")->assertOk();
    }

    public function test_the_cash_sales_of_the_day_cannot_be_rebuilt_by_a_cashier_from_the_sales_list(): void
    {
        $w = $this->world();
        $this->ring($w);
        $other = $this->ring($w);
        Sale::where('id', $other['id'])->update(['cashier_id' => $w['manager']->id]);

        $seen = $this->asUser($w['cashier'])->getJson('/api/v1/sales?payment_method=CASH')->assertOk();

        $this->assertSame(1, $seen->json('meta.total'), 'filters cannot reach past the cashier\'s own sales');
    }
}

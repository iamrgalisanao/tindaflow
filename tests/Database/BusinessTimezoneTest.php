<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 23 decision D1: the store's timezone (Asia/Manila, UTC+8) is its business timezone. These use instants that fall
 * on DIFFERENT calendar days in Manila and in UTC, so each would fail if dates were worked out in UTC:
 * 07:00 Manila on the 10th is 23:00 UTC on the 9th.
 */
class BusinessTimezoneTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_shift_opened_early_in_the_morning_belongs_to_that_days_business_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 07:00:00', 'Asia/Manila'));
        $store = Store::factory()->create();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);
        $token = $this->asUser($admin)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $enrollment = $this->asUser($admin)->postJson('/api/v1/terminal/enroll', ['token' => $token->json('token')]);

        $opened = $this->asUser($cashier, $enrollment)->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'], $this->key());

        $opened->assertStatus(201);
        $this->assertSame('2026-03-10', $opened->json('fiscal_day.business_date'), 'the local date, not the UTC date (2026-03-09)');
        $this->assertSame('2026-03-10', FiscalDay::firstOrFail()->business_date->toDateString());
    }

    public function test_a_date_range_means_the_stores_own_days(): void
    {
        $w = $this->world();
        $morning = $this->shiftOpenedAt($w, '2026-03-10 06:30:00'); // 22:30 UTC on the 9th
        $night = $this->shiftOpenedAt($w, '2026-03-10 23:30:00');   // 15:30 UTC on the 10th
        $next = $this->shiftOpenedAt($w, '2026-03-11 00:15:00');    // 16:15 UTC on the 10th
        $actor = $this->asUser($w['manager']);
        $ids = fn (string $query) => array_column($actor->getJson('/api/v1/shifts?'.$query.'&terminal_id='.$w['t1']->id)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$morning, $night], $ids('from=2026-03-10&to=2026-03-10'), 'the whole local day, morning included');
        $this->assertEqualsCanonicalizing([$next], $ids('from=2026-03-11&to=2026-03-11'));
        $this->assertNotContains($morning, $ids('to=2026-03-09'), 'a 06:30 shift on the 10th is not on the 9th');
    }

    public function test_the_journal_csv_carries_the_stores_utc_offset(): void
    {
        $w = $this->world();
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '100.00']],
        ], $this->key())->assertStatus(201);

        $csv = $this->asUser($w['manager'])->withHeader('Accept', 'text/csv')->get('/api/v1/electronic-journal-entries')->streamedContent();
        $rows = array_map('str_getcsv', array_slice(array_values(array_filter(explode("\n", $csv))), 1));

        $this->assertNotEmpty($rows);
        $this->assertMatchesRegularExpression('/\+08:00$/', $rows[0][0]);
    }

    /** The invoice's printed time is checked in InvoiceSnapshotV1RendererTest; this proves the wiring end to end. */
    public function test_an_invoice_prints_the_local_time_of_the_sale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 07:05:09', 'Asia/Manila'));
        $w = $this->world();
        $sale = $this->ring($w);

        $html = $this->asUser($w['manager'])->getJson('/api/v1/invoices/'.$sale['invoice']['id'])->assertOk()->json('render_html');

        $this->assertStringContainsString('2026-03-10 07:05:09', $html);
        $this->assertStringNotContainsString('2026-03-09 23:05:09', $html);
    }

    private function shiftOpenedAt(array $w, string $localTime): string
    {
        $day = FiscalDay::factory()->closed()->create(['store_id' => $w['storeId'], 'terminal_id' => $w['t1']->id, 'opened_at' => $localTime]);

        return Shift::factory()->closed()->create([
            'terminal_id' => $w['t1']->id, 'fiscal_day_id' => $day->id, 'cashier_id' => User::factory()->create(['store_id' => $w['storeId']])->id, 'opened_at' => $localTime,
        ])->id;
    }
}

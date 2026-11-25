<?php

namespace Tests\Database;

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016, slice 2.2b: the reports that name a sale's document (sales by date range, voids, refunds, discounts) name a slip
 * sale by its slip number. A store that uses slips gets one more CSV column at the END, as the export contract allows; a store
 * that has only ever issued invoices gets exactly the columns and rows it had.
 */
class SlipReportsHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const PINNED = [
        'sales-by-date-range' => 'sold_at,invoice_number,transaction_number,terminal_id,cashier_id,subtotal,discount_total,grand_total,status',
        'voids' => 'void_id,sale_id,invoice_number,requested_by,approved_by,reason,terminal_id,fiscal_day_id,resolved_at,sale_grand_total',
        'refunds' => 'refund_id,sale_id,invoice_number,requested_by,approved_by,reason,terminal_id,fiscal_day_id,refunded_at,refund_total',
        'discounts' => 'sold_at,invoice_number,line_discount_total,order_discount_total,discount_total',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    /** @param  array<string, mixed>  $w */
    private function useMode(array $w, string $mode): void
    {
        $w['t1']->update(['terminal_code' => 'T-01']);
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => $mode, 'slip_footer' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * In invoice mode the manager's till needs a fiscal installation too (the shared world only gives the cashier's one).
     *
     * @param  array<string, mixed>  $w
     */
    private function assignManagersTill(array $w): void
    {
        $installation = DB::table('terminal_fiscal_installations')->where('terminal_id', $w['t1']->id)->value('fiscal_installation_id');
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $w['storeId'], 'terminal_id' => $w['t2']->id, 'fiscal_installation_id' => $installation,
            'effective_from' => now()->subYear(), 'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A discounted sale by the manager (who may discount), then one refunded in part and one voided, so that all four reports
     * have a row for it.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, string> report slug => the document number its row should name
     */
    private function trade(array $w): array
    {
        $sell = fn (array $extra = []) => $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', $extra + [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => [['method' => 'CASH', 'amount' => '200.00']],
        ], $this->key())->assertStatus(201)->json();
        $number = fn (array $sale): string => $sale['document_reference'];

        $discounted = $sell(['order_level_discount_amount' => '10.00']);
        $refunded = $sell();
        $voided = $sell();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$refunded['id']}/refunds", [
            'items' => [['sale_item_id' => $refunded['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Returned',
        ], $this->key())->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);

        return ['sales-by-date-range' => $number($discounted), 'discounts' => $number($discounted), 'refunds' => $number($refunded), 'voids' => $number($voided)];
    }

    /** @param  array<string, mixed>  $w */
    private function report(array $w, string $slug): TestResponse
    {
        return $this->asUser($w['manager'])->getJson("/api/v1/reports/{$slug}")->assertOk();
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array{0: string, 1: list<array<string, string>>} the header line, and the rows by column name
     */
    private function csv(array $w, string $slug): array
    {
        $body = trim($this->asUser($w['manager'])->withHeader('Accept', 'text/csv')->get("/api/v1/reports/{$slug}")->assertOk()->getContent());
        $lines = explode("\n", $body);
        $header = str_getcsv(array_shift($lines), escape: '');

        return [implode(',', $header), array_map(fn (string $line) => array_combine($header, str_getcsv($line, escape: '')), $lines)];
    }

    public function test_a_store_that_has_only_ever_issued_invoices_gets_the_reports_it_always_had(): void
    {
        $w = $this->world();
        $this->assignManagersTill($w);
        $this->trade($w);

        foreach (self::PINNED as $slug => $columns) {
            [$header, $rows] = $this->csv($w, $slug);
            $this->assertSame($columns, $header, $slug);
            $this->assertNotEmpty($rows, $slug);
            foreach ($this->report($w, $slug)->json('rows') as $row) {
                $this->assertArrayNotHasKey('slip_number', $row, $slug);
            }
            $this->assertArrayNotHasKey('extra_columns', $this->report($w, $slug)->json(), $slug);
        }
    }

    public function test_a_slip_sale_is_named_by_its_slip_number_in_each_report_and_export(): void
    {
        $w = $this->world();
        $this->useMode($w, 'SLIP');
        $expected = $this->trade($w);

        foreach (self::PINNED as $slug => $columns) {
            $slips = array_column($this->report($w, $slug)->json('rows'), 'slip_number');
            $this->assertContains($expected[$slug], $slips, $slug);

            [$header, $rows] = $this->csv($w, $slug);
            $this->assertSame($columns.',slip_number', $header, "{$slug}: appended at the end, nothing moved");
            $this->assertContains($expected[$slug], array_column($rows, 'slip_number'), $slug);
            $this->assertSame([''], array_values(array_unique(array_column($rows, 'invoice_number'))), "{$slug}: a slip sale has no invoice number");
        }
    }

    public function test_a_store_that_changed_to_slips_names_each_sale_by_its_own_document(): void
    {
        $w = $this->world();
        $invoiced = $this->ring($w)['invoice_number'];
        $this->useMode($w, 'SLIP');
        $slipped = $this->ring($w)['document_reference'];

        [, $rows] = $this->csv($w, 'sales-by-date-range');

        $this->assertEqualsCanonicalizing([[$invoiced, ''], ['', $slipped]], array_map(fn (array $row) => [$row['invoice_number'], $row['slip_number']], $rows));
    }

    public function test_after_going_back_to_invoices_the_earlier_slips_are_still_named(): void
    {
        $w = $this->world();
        $this->useMode($w, 'SLIP');
        $slipped = $this->ring($w)['document_reference'];
        $this->useMode($w, 'INVOICE');

        [$header, $rows] = $this->csv($w, 'sales-by-date-range');

        $this->assertStringEndsWith(',slip_number', $header);
        $this->assertSame([$slipped], array_column($rows, 'slip_number'));
    }

    public function test_a_slip_store_with_nothing_to_report_still_has_the_column_in_its_export(): void
    {
        $w = $this->world();
        $this->useMode($w, 'SLIP');

        foreach (self::PINNED as $slug => $columns) {
            [$header, $rows] = $this->csv($w, $slug);
            $this->assertSame([$columns.',slip_number', []], [$header, $rows], $slug);
        }
    }

    public function test_another_stores_slips_change_nothing_here(): void
    {
        $w = $this->world();
        $this->ring($w);
        $other = Store::factory()->create();
        DB::table('store_settings')->insert(['store_id' => $other->id, 'business_name' => 'Other', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP', 'created_at' => now(), 'updated_at' => now()]);

        [$header] = $this->csv($w, 'sales-by-date-range');

        $this->assertSame(self::PINNED['sales-by-date-range'], $header);
    }
}

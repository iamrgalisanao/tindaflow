<?php

namespace Tests\Database;

use App\Models\ZReading;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 section 4 / slice 2.1: bank transfer is an ordinary non-cash way of paying and of refunding. An optional reference
 * number is kept with it. It never counts as cash in the drawer, and TindaFlow does not check that the money arrived.
 */
class BankTransferHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $payments
     */
    private function sell(array $w, array $payments): TestResponse
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => $payments,
        ], $this->key());
    }

    /** @param  array<string, mixed>  $w */
    private function slipMode(array $w): void
    {
        $w['t1']->update(['terminal_code' => 'T-01']);
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP', 'slip_footer' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_bank_transfer_is_accepted_without_a_reference(): void
    {
        $w = $this->world();

        $sale = $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->assertStatus(201)->json();

        $payment = DB::table('payments')->where('sale_id', $sale['id'])->sole();
        $this->assertSame('BANK_TRANSFER', $payment->method);
        $this->assertNull($payment->reference_note);
    }

    public function test_the_reference_is_kept_and_printed_on_the_slip(): void
    {
        $w = $this->world();
        $this->slipMode($w);

        $sale = $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00', 'external_reference' => 'IBFT 0045-221']])->assertStatus(201)->json();

        $this->assertSame('IBFT 0045-221', DB::table('payments')->where('sale_id', $sale['id'])->value('reference_note'));
        $html = $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$sale['slip']['id']}")->assertOk()->json('render_html');
        $this->assertStringContainsString('<span>Bank transfer</span>', $html);
        $this->assertStringContainsString('Ref: IBFT 0045-221', $html);
        $this->assertStringNotContainsString('BANK_TRANSFER', $html);
    }

    public function test_an_invoice_names_it_in_words(): void
    {
        $w = $this->world();

        $sale = $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->assertStatus(201)->json();

        $html = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$sale['invoice']['id']}")->assertOk()->json('render_html');
        $this->assertStringContainsString('BANK TRANSFER', $html);
        $this->assertStringNotContainsString('BANK_TRANSFER', $html);
    }

    public function test_a_reference_longer_than_the_database_keeps_is_refused_before_anything_is_sold(): void
    {
        $w = $this->world();

        $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00', 'external_reference' => str_repeat('x', 256)]])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, DB::table('sales')->count());
        $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00', 'external_reference' => str_repeat('x', 255)]])->assertStatus(201);
    }

    public function test_it_is_never_cash_in_the_drawer_and_has_its_own_line_in_the_readings(): void
    {
        $w = $this->world();
        // One sale paid wholly by bank transfer, and one split with cash: only the cash part reaches the drawer.
        $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->assertStatus(201);
        $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '150.00'], ['method' => 'CASH', 'amount' => '50.00']])->assertStatus(201);

        $expected = bcadd((string) $w['cashierShift']->opening_cash, '50.00', 2);
        $closed = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => $expected], $this->key())->assertOk();
        $this->assertSame($expected, (string) DB::table('shifts')->where('id', $w['cashierShift']->id)->value('expected_cash'));
        $this->assertNotNull($closed->json());

        $this->asUser($w['manager'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
        $totals = ZReading::where('fiscal_day_id', $w['cashierShift']->fiscal_day_id)->sole()->totals_snapshot;
        $this->assertSame('350.00', $totals['payment_breakdown']['BANK_TRANSFER']);
        $this->assertSame('50.00', $totals['payment_breakdown']['CASH']);

        $printed = $this->asUser($w['manager'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/z-reading/prints")->assertStatus(201)->json('render_html');
        $this->assertStringContainsString('BANK TRANSFER', $printed);
        $this->assertStringNotContainsString('BANK_TRANSFER', $printed);
    }

    public function test_a_refund_can_be_paid_back_by_bank_transfer_with_no_cash_leaving_the_drawer(): void
    {
        $w = $this->world();
        $sale = $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->assertStatus(201)->json();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'BANK_TRANSFER', 'amount' => '100.00', 'external_reference' => 'BACK-77']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);

        $settlement = DB::table('refund_settlements')->sole();
        $this->assertSame(['BANK_TRANSFER', 'BACK-77'], [$settlement->payment_method, $settlement->external_reference]);
        $opening = (string) $w['managerShift']->opening_cash;
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/shifts/{$w['managerShift']->id}/close", ['declared_cash' => $opening], $this->key())->assertOk();
        $this->assertSame(bcadd($opening, '0', 2), (string) DB::table('shifts')->where('id', $w['managerShift']->id)->value('expected_cash'));
    }

    public function test_the_sales_list_can_be_filtered_by_it(): void
    {
        $w = $this->world();
        $transfer = $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->json('id');
        $this->sell($w, [['method' => 'GCASH', 'amount' => '200.00']])->assertStatus(201);

        $listed = $this->asUser($w['manager'])->getJson('/api/v1/sales?payment_method=BANK_TRANSFER')->assertOk()->json('data');

        $this->assertSame([$transfer], array_column($listed, 'id'));
    }

    public function test_a_method_the_system_does_not_know_is_still_refused(): void
    {
        $w = $this->world();

        $this->sell($w, [['method' => 'BANK', 'amount' => '200.00']])->assertStatus(422);

        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_the_migration_refuses_to_roll_back_once_a_bank_transfer_is_recorded(): void
    {
        $w = $this->world();
        $this->sell($w, [['method' => 'BANK_TRANSFER', 'amount' => '200.00']])->assertStatus(201);
        $migration = require database_path('migrations/2026_12_01_100000_add_bank_transfer_to_payment_method_checks.php');

        try {
            $migration->down();
            $this->fail('the rollback must refuse');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('cannot be rolled back', $refused->getMessage());
        }

        $this->assertSame(1, DB::table('payments')->where('method', 'BANK_TRANSFER')->count(), 'no sales record was deleted');
    }
}

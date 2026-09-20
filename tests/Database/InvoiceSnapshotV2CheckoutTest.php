<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\FiscalInstallationAccreditation;
use App\Models\FiscalInstallationPermitToUse;
use App\Models\Invoice;
use Carbon\Carbon;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 24 decision 4: checkout writes snapshot schema version 2. It copies the machine registration data on file for
 * the terminal's fiscal installation at the moment of sale (effective-dated, never invented), the payments and the
 * change; later changes to that data never touch an issued invoice.
 */
class InvoiceSnapshotV2CheckoutTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function installation(array $w): FiscalInstallation
    {
        return FiscalInstallation::where('store_id', $w['storeId'])->firstOrFail();
    }

    private function permit(FiscalInstallation $installation, array $overrides = []): FiscalInstallationPermitToUse
    {
        return FiscalInstallationPermitToUse::create($overrides + [
            'fiscal_installation_id' => $installation->id, 'number' => 'FP012024-045-0123456-00000', 'min' => '24010112345678901',
            'date' => '2026-01-15', 'effective_from' => '2026-01-15', 'effective_to' => null,
        ]);
    }

    private function accreditation(FiscalInstallation $installation, array $overrides = []): FiscalInstallationAccreditation
    {
        return FiscalInstallationAccreditation::create($overrides + [
            'fiscal_installation_id' => $installation->id, 'number' => '045-2024-00012', 'date' => '2024-03-01',
            'effective_from' => '2024-03-01', 'effective_to' => '2029-02-28',
        ]);
    }

    private function snapshotOf(array $sale): array
    {
        return Invoice::findOrFail($sale['invoice']['id'])->invoice_snapshot_json;
    }

    private function html(array $w, array $sale): string
    {
        return $this->asUser($w['manager'])->getJson('/api/v1/invoices/'.$sale['invoice']['id'])->assertOk()->json('render_html');
    }

    public function test_a_new_invoice_is_version_2_and_carries_the_registration_data_on_file(): void
    {
        $w = $this->world();
        $installation = $this->installation($w);
        $installation->update(['machine_serial_number' => 'SN-0042', 'software_version' => '1.4.2']);
        $this->permit($installation);
        $this->accreditation($installation);

        $sale = $this->ring($w);
        $snapshot = $this->snapshotOf($sale);

        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertEquals([
            'min' => '24010112345678901',
            'machine_serial_number' => 'SN-0042',
            'software_version' => '1.4.2',
            'ptu_number' => 'FP012024-045-0123456-00000',
            'ptu_date' => '2026-01-15',
            'accreditation_number' => '045-2024-00012',
            'accreditation_date' => '2024-03-01',
            'accreditation_valid_from' => '2024-03-01',
            'accreditation_valid_to' => '2029-02-28',
        ], $snapshot['registration']);
        $this->assertNull($snapshot['discount_beneficiary']);

        $html = $this->html($w, $sale);
        $this->assertStringContainsString('MIN: 24010112345678901 &middot; S/N: SN-0042', $html);
        $this->assertStringContainsString('PTU No.: FP012024-045-0123456-00000 &middot; 2026-01-15', $html);
        $this->assertStringContainsString('Accreditation No.: 045-2024-00012', $html);
    }

    public function test_a_terminal_with_nothing_on_file_gets_null_fields_and_no_invented_lines(): void
    {
        $w = $this->world();

        $sale = $this->ring($w);
        $registration = $this->snapshotOf($sale)['registration'];

        foreach (['min', 'ptu_number', 'ptu_date', 'accreditation_number', 'accreditation_date', 'accreditation_valid_from', 'accreditation_valid_to'] as $field) {
            $this->assertNull($registration[$field], $field);
        }
        $html = $this->html($w, $sale);
        foreach (['MIN:', 'PTU', 'Accreditation'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_only_the_permit_and_accreditation_in_force_on_the_day_of_sale_are_used(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00', 'Asia/Manila'));
        $w = $this->world();
        $installation = $this->installation($w);
        $this->permit($installation, ['number' => 'OLD-PTU', 'min' => 'OLD-MIN', 'effective_from' => '2025-01-01', 'effective_to' => '2026-03-31']);
        $this->permit($installation, ['number' => 'CURRENT-PTU', 'min' => 'CURRENT-MIN', 'effective_from' => '2026-04-01', 'effective_to' => null]);
        $this->permit($installation, ['number' => 'FUTURE-PTU', 'min' => 'FUTURE-MIN', 'effective_from' => '2026-12-01', 'effective_to' => '2027-11-30']);
        $this->accreditation($installation, ['number' => 'EXPIRED-ACC', 'effective_from' => '2019-01-01', 'effective_to' => '2024-12-31']);

        $registration = $this->snapshotOf($this->ring($w))['registration'];
        Carbon::setTestNow();

        $this->assertSame('CURRENT-PTU', $registration['ptu_number']);
        $this->assertSame('CURRENT-MIN', $registration['min']);
        $this->assertNull($registration['accreditation_number'], 'an accreditation that has expired is not printed');
    }

    public function test_the_payments_the_amount_tendered_and_the_change_are_recorded(): void
    {
        $w = $this->world();

        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'GCASH', 'amount' => '40.00', 'external_reference' => 'GC-1'], ['method' => 'CASH', 'amount' => '100.00']],
        ], $this->key())->assertStatus(201);
        $snapshot = $this->snapshotOf($response->json());

        $this->assertEquals([['method' => 'GCASH', 'amount' => '40.00'], ['method' => 'CASH', 'amount' => '100.00']], $snapshot['payments']);
        $this->assertSame('140.00', $snapshot['amount_tendered']);
        $this->assertSame('40.00', $snapshot['change']);
        $this->assertSame('100.00', $snapshot['grand_total']);

        $html = $this->html($w, $response->json());
        $this->assertStringContainsString('<span>GCASH</span><span>PHP 40.00</span>', $html);
        $this->assertStringContainsString('<span>CASH</span><span>PHP 100.00</span>', $html);
        $this->assertStringContainsString('<span>Change</span><span>PHP 40.00</span>', $html);
    }

    public function test_an_exact_payment_has_no_change_line(): void
    {
        $w = $this->world();

        $sale = $this->ring($w);

        $this->assertSame('0.00', $this->snapshotOf($sale)['change']);
        $this->assertStringNotContainsString('Change', $this->html($w, $sale));
    }

    public function test_a_later_change_to_the_registration_never_rewrites_an_issued_invoice(): void
    {
        $w = $this->world();
        $installation = $this->installation($w);
        $installation->update(['machine_serial_number' => 'SN-BEFORE']);
        $permit = $this->permit($installation);
        $sale = $this->ring($w);
        $before = $this->html($w, $sale);

        $installation->update(['machine_serial_number' => 'SN-AFTER']);
        $permit->update(['number' => 'CHANGED-PTU', 'min' => 'CHANGED-MIN']);
        $this->ring($w);

        $this->assertSame($before, $this->html($w, $sale), 'the issued invoice is byte-for-byte what it was');
        $this->assertStringContainsString('SN-BEFORE', $before);
        $this->assertStringNotContainsString('CHANGED', $before);
    }

    public function test_a_reprint_shows_the_same_registration_data_and_the_reprint_mark(): void
    {
        $w = $this->world();
        $this->permit($this->installation($w));
        $sale = $this->ring($w);

        $reprint = $this->asUser($w['manager'], $w['enroll1'])->postJson("/api/v1/invoices/{$sale['invoice']['id']}/reprints", [], $this->key())->assertStatus(201);

        $html = $reprint->json('invoice.render_html');
        $this->assertStringContainsString('REPRINT &mdash; COPY', $html);
        $this->assertStringContainsString('PTU No.: FP012024-045-0123456-00000', $html);
    }
}

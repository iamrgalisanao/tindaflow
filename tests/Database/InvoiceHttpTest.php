<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Sale;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml invoiceGet / invoiceReprint (invariants #15/#16, ADR-006/007). A reprint is a record that a
 * copy was produced; it must leave the invoice, the sale, the series counter and every total exactly as
 * they were.
 */
class InvoiceHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the world and the invoice id + number */
    private function rung(): array
    {
        $w = $this->world();
        $sale = $this->ring($w);

        return [$w, ['id' => $sale['invoice']['id'], 'number' => $sale['invoice_number'], 'sale_id' => $sale['id']]];
    }

    private function reprint(array $w, string $invoiceId, ?array $headers = null, string $as = 'cashier')
    {
        return $this->asUser($w[$as], $w['enroll1'])->postJson("/api/v1/invoices/{$invoiceId}/reprints", [], $headers ?? $this->key());
    }

    // ------------------------------------------------------------------- get

    public function test_get_returns_the_plain_original_rendered_from_its_snapshot(): void
    {
        [$w, $invoice] = $this->rung();

        $response = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$invoice['id']}");

        $response->assertOk();
        $response->assertJson([
            'id' => $invoice['id'],
            'invoice_number' => $invoice['number'],
            'sale_id' => $invoice['sale_id'],
            'terminal_id' => $w['t1']->id,
            'schema_version' => 1,
            'tax_registration_type_snapshot' => 'VAT',
        ]);
        $this->assertSame(
            ['id', 'invoice_number', 'issued_at', 'schema_version', 'sale_id', 'invoice_series_id', 'terminal_id', 'fiscal_installation_id', 'seller_registered_name_snapshot', 'tax_registration_type_snapshot', 'terminal_code_snapshot', 'render_html'],
            array_keys($response->json()),
        );
        $html = $response->json('render_html');
        $this->assertStringContainsString($invoice['number'], $html);
        $this->assertStringContainsString('PHP 200.00', $html);
        $this->assertStringNotContainsString('REPRINT', $html);
    }

    public function test_get_is_store_scoped_and_needs_a_session_but_no_terminal(): void
    {
        [$w, $invoice] = $this->rung();
        [, $foreign] = $this->rung();

        foreach ([(string) Str::uuid(), $foreign['id']] as $id) {
            $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$id}")->assertStatus(404)->assertJson(['error' => ['code' => 'INVOICE_NOT_FOUND']]);
        }
        $this->asUser($w['manager'])->getJson("/api/v1/invoices/{$invoice['id']}")->assertOk();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $id = (string) Str::uuid();

        $this->getJson("/api/v1/invoices/{$id}")->assertStatus(401);
        $this->postJson("/api/v1/invoices/{$id}/reprints", [], $this->key())->assertStatus(401);
    }

    // --------------------------------------------------------------- reprint

    public function test_a_reprint_returns_the_unchanged_invoice_with_the_mark_and_records_the_occurrence(): void
    {
        [$w, $invoice] = $this->rung();
        $original = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$invoice['id']}")->json();

        $response = $this->reprint($w, $invoice['id']);

        $response->assertStatus(201);
        $this->assertSame(['reprint_event_id', 'invoice', 'is_reprint', 'reprinted_at', 'requested_by'], array_keys($response->json()));
        $this->assertTrue($response->json('is_reprint'));
        $this->assertSame($w['cashier']->id, $response->json('requested_by'));
        $this->assertNotNull($response->json('reprinted_at'));

        // Byte-for-byte the same invoice except for the rendered mark.
        $reprinted = $response->json('invoice');
        $this->assertSame(array_diff_key($original, ['render_html' => 1]), array_diff_key($reprinted, ['render_html' => 1]));
        $this->assertStringContainsString('REPRINT &mdash; COPY', $reprinted['render_html']);
        $this->assertStringContainsString($invoice['number'], $reprinted['render_html']);
        $this->assertSame($original['issued_at'], $reprinted['issued_at']);

        // The occurrence is the audit event; the journal entry projects it without touching the original.
        $event = AuditEvent::findOrFail($response->json('reprint_event_id'));
        $this->assertSame($event->occurred_at->toJSON(), $response->json('reprinted_at'));
        $this->assertSame('INVOICE_REPRINTED', $event->event_type);
        $this->assertSame($w['cashier']->id, $event->actor_user_id);
        $this->assertSame($w['t1']->id, $event->terminal_id);
        $this->assertSame($invoice['id'], $event->entity_id);

        $entry = ElectronicJournalEntry::where('source_id', $event->id)->firstOrFail();
        $this->assertSame('INVOICE', $entry->event_type);
        $this->assertSame('invoice_reprint', $entry->source_type);
        $this->assertSame($event->id, $entry->audit_event_id);
        $this->assertTrue($entry->payload_json['is_reprint']);
        $this->assertSame($invoice['number'], $entry->payload_json['invoice_number']);
        $this->assertSame(1, ElectronicJournalEntry::where('source_type', 'invoice')->where('source_id', $invoice['id'])->count());
    }

    public function test_a_reprint_changes_nothing_about_the_fiscal_record(): void
    {
        [$w, $invoice] = $this->rung();
        $series = InvoiceSeries::firstOrFail();
        $before = [
            'invoice' => Invoice::findOrFail($invoice['id'])->getAttributes(),
            'sale' => Sale::findOrFail($invoice['sale_id'])->getAttributes(),
            'series' => $series->current_number,
            'invoices' => Invoice::count(),
            'sales' => Sale::count(),
        ];

        $this->reprint($w, $invoice['id'])->assertStatus(201);
        $this->reprint($w, $invoice['id'])->assertStatus(201);

        $this->assertEquals($before['invoice'], Invoice::findOrFail($invoice['id'])->getAttributes());
        $this->assertEquals($before['sale'], Sale::findOrFail($invoice['sale_id'])->getAttributes());
        $this->assertSame($before['series'], $series->fresh()->current_number);
        $this->assertSame($before['invoices'], Invoice::count());
        $this->assertSame($before['sales'], Sale::count());

        // And the plain original is still the plain original afterwards.
        $after = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$invoice['id']}");
        $this->assertStringNotContainsString('REPRINT', $after->json('render_html'));
    }

    public function test_every_reprint_is_its_own_auditable_occurrence(): void
    {
        [$w, $invoice] = $this->rung();

        $first = $this->reprint($w, $invoice['id'])->json();
        $second = $this->reprint($w, $invoice['id'], null, 'manager')->json();

        $this->assertNotSame($first['reprint_event_id'], $second['reprint_event_id']);
        $this->assertSame(2, AuditEvent::where('event_type', 'INVOICE_REPRINTED')->count());
        $this->assertSame(2, ElectronicJournalEntry::where('source_type', 'invoice_reprint')->count());
        $this->assertSame($w['manager']->id, $second['requested_by']);
    }

    public function test_a_retried_reprint_returns_the_same_occurrence_and_records_it_once(): void
    {
        [$w, $invoice] = $this->rung();
        $headers = $this->key();

        $first = $this->reprint($w, $invoice['id'], $headers);
        $second = $this->reprint($w, $invoice['id'], $headers);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('reprint_event_id'), $second->json('reprint_event_id'));
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, AuditEvent::where('event_type', 'INVOICE_REPRINTED')->count());
        $this->assertSame(1, ElectronicJournalEntry::where('source_type', 'invoice_reprint')->count());
    }

    public function test_the_same_key_for_a_different_invoice_is_a_conflict(): void
    {
        [$w, $invoice] = $this->rung();
        $other = $this->ring($w);
        $headers = $this->key();

        $this->reprint($w, $invoice['id'], $headers)->assertStatus(201);
        $this->reprint($w, $other['invoice']['id'], $headers)->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $this->assertSame(1, AuditEvent::where('event_type', 'INVOICE_REPRINTED')->count());
    }

    public function test_the_idempotency_key_and_an_enrolled_terminal_are_required(): void
    {
        [$w, $invoice] = $this->rung();

        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/invoices/{$invoice['id']}/reprints", [])
            ->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
        $this->asUser($w['cashier'])->postJson("/api/v1/invoices/{$invoice['id']}/reprints", [], $this->key())
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->assertSame(0, AuditEvent::where('event_type', 'INVOICE_REPRINTED')->count());
    }

    public function test_an_unknown_or_foreign_invoice_is_not_found_and_records_nothing(): void
    {
        [$w] = $this->rung();
        [, $foreign] = $this->rung();

        foreach ([(string) Str::uuid(), $foreign['id']] as $id) {
            $this->reprint($w, $id)->assertStatus(404)->assertJson(['error' => ['code' => 'INVOICE_NOT_FOUND']]);
        }
        $this->assertSame(0, AuditEvent::where('event_type', 'INVOICE_REPRINTED')->count());
    }

    public function test_the_invoice_of_a_voided_sale_can_still_be_reprinted_as_the_same_document(): void
    {
        [$w, $invoice] = $this->rung();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$invoice['sale_id']}/void", ['reason' => 'x'], $this->key())->assertStatus(201);

        $response = $this->reprint($w, $invoice['id']);

        $response->assertStatus(201);
        $this->assertStringContainsString($invoice['number'], $response->json('invoice.render_html'));
    }
}

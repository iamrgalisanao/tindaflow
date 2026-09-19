<?php

namespace App\Services\Invoicing;

use App\Domain\Exceptions\InvoiceNotFoundException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Invoice;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;

/**
 * openapi.yaml invoiceReprint (invariants #15/#16). A reprint changes nothing about the fiscal record: no
 * invoice, sale, series counter or reading is touched and no number is allocated. It only records that a
 * copy was produced -- an INVOICE_REPRINTED audit event, whose id *is* the reprint occurrence's id, plus its
 * journal entry -- so every copy stays independently auditable however many are printed.
 *
 * The journal's event type list is closed and has no reprint member, so the entry is an `INVOICE` entry
 * whose source is the reprint occurrence itself (`source_type = invoice_reprint`, `source_id` = the audit
 * event) and whose payload says `is_reprint`. That keeps the "one entry per (source, event type)" rule
 * intact and never disturbs the original INVOICE entry (`source_type = invoice`).
 *
 * Idempotent per (terminal, key): a retry returns the same occurrence, and because the result is rebuilt
 * from the stored audit event (its id, actor and time) the response is identical on replay.
 */
final class InvoiceReprintService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
    ) {}

    /** @return array{event: AuditEvent, invoice: Invoice} */
    public function reprint(Terminal $terminal, User $user, string $invoiceId, string $idempotencyKey): array
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::InvoiceReprint,
            $this->requestHasher->hash(['invoice_id' => $invoiceId]),
            fn () => $this->record($terminal, $user, $invoiceId),
        );

        $event = AuditEvent::findOrFail($result->resultResourceId);

        return ['event' => $event, 'invoice' => Invoice::findOrFail($event->entity_id)];
    }

    /** @throws InvoiceNotFoundException an invoice of another store is indistinguishable from a missing one */
    public function find(string $invoiceId, string $storeId): Invoice
    {
        return Invoice::where('store_id', $storeId)->find($invoiceId) ?? throw InvoiceNotFoundException::forId($invoiceId);
    }

    private function record(Terminal $terminal, User $user, string $invoiceId): OperationOutcome
    {
        $invoice = $this->find($invoiceId, $user->store_id);

        $event = AuditEvent::create([
            'store_id' => $invoice->store_id,
            'event_type' => 'INVOICE_REPRINTED',
            'actor_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'after_metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'sale_id' => $invoice->sale_id],
        ]);
        // The database stamps the event; the journal entry (same transaction, same default) and every
        // response use that one moment, so a replay reproduces it exactly.
        $event->refresh();

        ElectronicJournalEntry::create([
            'store_id' => $invoice->store_id,
            'terminal_id' => $terminal->id,
            'event_type' => 'INVOICE',
            'source_type' => 'invoice_reprint',
            'source_id' => $event->id,
            'audit_event_id' => $event->id,
            'payload_json' => [
                'is_reprint' => true,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'sale_id' => $invoice->sale_id,
                'grand_total' => $invoice->invoice_snapshot_json['grand_total'] ?? null,
                'reprinted_at' => $event->occurred_at->toIso8601String(),
                'requested_by' => $user->id,
            ],
        ]);

        return new OperationOutcome(resultType: 'invoice_reprint', resultResourceId: $event->id);
    }
}

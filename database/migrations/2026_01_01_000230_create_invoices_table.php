<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.7 INVOICE -- 1:1 with a COMPLETED sale (invariant
// #10). invoice_number is the accountable fiscal serial: digits-only,
// leading-zero-preserving (Stage 4 pattern ^[0-9]{6,}$), stored as a
// STRING (never an integer/bigint -- a number would silently drop
// leading zeroes, api-design.md SS17). It is the FORMATTED display value,
// deliberately distinct from invoice_series.current_number (the raw
// integer counter) -- Stage 5 instruction SS20/SS21.
//
// Uniqueness scope (Stage 5 instruction SS21): UNIQUE(invoice_series_id,
// invoice_number) -- scoped to the series that allocated it, not
// globally or per-store, since that is the precise resource the number
// was drawn from and the frozen model already allows more than one
// invoice_series per store (domain-model.md SS2.6 imposes no 1:1 store
// cardinality).
//
// No updated_at: an invoice is immutable once issued (invariant #16,
// ADR-006) -- there is no application-level UPDATE path, ever.
//
// Owner hardening pass follow-up, 2026-09-16: `store_id` added
// (disclosed denormalization, same pattern as
// terminal_fiscal_installations' earlier fix) to close the Invoice half
// of DB-INV-063 the owner asked to be split in two:
//   (A) STRUCTURAL store coherence -- the Sale and InvoiceSeries an
//       Invoice cites must belong to the same Store. This IS a
//       relational-integrity fact PostgreSQL can enforce declaratively,
//       and the three composite FKs below do exactly that -- see
//       context-integrity-matrix.md.
//   (B) TEMPORAL/fiscal eligibility -- whether the selected
//       InvoiceSeries was ACTIVE, or the FiscalInstallation assignment
//       was effective, AT `issued_at` specifically. A composite FK
//       cannot express "valid at this historical instant"; that is
//       Stage 6 transactional domain logic (lock/resolve the series,
//       verify eligibility, allocate, persist -- all in one
//       transaction), classified TRANSACTION in constraint-register.md,
//       not weakened into a database constraint it cannot honestly be.
// No terminal_id/shift_id/fiscal_day_id was added beyond what already
// existed (`terminal_id` was already part of the frozen ERD) --
// duplicating Sale's shift/fiscal_day context onto Invoice was
// explicitly out of scope for this fix (Invoice has no independent
// eligibility condition tied to those, unlike Sale itself).
//
// Owner hardening pass follow-up #2, 2026-09-16: the store-level fix
// above still permitted a Store A invoice citing a Store A sale that
// was actually finalized on Terminal 01, while the invoice itself
// declared Terminal 02 -- both terminals legitimately belong to Store
// A, so the original `invoices_store_sale_fk`/`invoices_store_terminal_fk`
// pair never caught it. Sale finalization and Invoice issuance are one
// atomic operation in the frozen checkout architecture (ADR-003), so
// `invoices.terminal_id` disagreeing with `sales.terminal_id` is a
// structurally impossible state, not merely an unlikely one -- treated
// as a DATABASE invariant accordingly, not deferred to Stage 6.
// `invoices_store_terminal_sale_fk` below replaces both prior composite
// FKs with one three-column FK against `sales(store_id, terminal_id,
// id)`, which is strictly stronger than either: it implies
// `invoices_store_sale_fk`'s guarantee (same store) directly, and
// implies `invoices_store_terminal_fk`'s guarantee (the invoice's
// terminal genuinely belongs to its store) transitively, since `sales`
// itself already carries `sales_store_terminal_fk` proving its own
// terminal belongs to its own store -- an invoice's terminal, once
// proven identical to its sale's terminal, inherits that same
// guarantee for free. Keeping both narrower FKs alongside this one
// would prove nothing the three-column FK doesn't already prove, so
// they are removed rather than kept as redundant constraints.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('invoice_series_id')->constrained('invoice_series')->restrictOnDelete();
            $table->foreignUuid('fiscal_installation_id')->nullable()->constrained('fiscal_installations')->restrictOnDelete();
            $table->string('invoice_number');
            $table->timestampTz('issued_at');
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->string('seller_registered_name_snapshot');
            $table->string('tax_registration_type_snapshot'); // VAT|NON_VAT
            $table->string('terminal_code_snapshot');
            $table->jsonb('invoice_snapshot_json');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('sale_id');
            $table->unique(['invoice_series_id', 'invoice_number']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_tax_registration_type_check CHECK (tax_registration_type_snapshot IN ('VAT','NON_VAT'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_invoice_number_digits_check CHECK (invoice_number ~ '^[0-9]{6,}$')");

        // Structural store coherence (DATABASE-enforced): an Invoice's
        // Sale and InvoiceSeries must both belong to the same Store this
        // Invoice itself declares. Without these, PostgreSQL would
        // happily accept a Store A sale documented by a Store B
        // invoice_series -- every individual single-column FK would
        // still be satisfied. Verified live by InvoiceContextIntegrityTest.
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_store_series_fk FOREIGN KEY (store_id, invoice_series_id) REFERENCES invoice_series (store_id, id)');
        // Invoice/Sale terminal identity (DATABASE-enforced, owner
        // hardening pass follow-up #2): proves invoices.terminal_id
        // equals sales.terminal_id for the sale this invoice documents,
        // not merely that both belong to the same store. Strictly
        // stronger than -- and replaces -- the narrower
        // (store_id, sale_id) and (store_id, terminal_id) composite FKs
        // this migration originally added; see the comment above this
        // class for why those two are now redundant.
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_store_terminal_sale_fk FOREIGN KEY (store_id, terminal_id, sale_id) REFERENCES sales (store_id, terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

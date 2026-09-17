<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.7 SALE -- the transaction aggregate root. No
// persisted DRAFT status (invariant #1) -- the row does not exist until
// checkout finalization commits. Invariant #2's immutability is scoped
// to financial/snapshot fields, not the row as a whole: state-machines.md
// SS1/SS2 explicitly transitions `sale.status` (COMPLETED -> VOIDED via
// the void's own APPROVED -> VOIDED execution, and COMPLETED ->
// PARTIALLY_REFUNDED/REFUNDED per invariant #31) -- a real, frozen-model
// UPDATE, not merely a hypothetical one. `updated_at` is therefore
// included (Stage 5 instruction SS46: "for lifecycle aggregates such as
// REQUESTED -> final Void/Refund, updated_at may be meaningful" --
// `sales` is this same class of lifecycle aggregate, not a pure
// append-only table like `sale_items`/`payments`/`invoices`, which keep
// no `updated_at` because nothing in the frozen model ever changes them
// after creation).
//
// idempotency_key: retained on `sales` for the CHECKOUT operation
// specifically, matching ADR-010's original per-operation scope
// ("terminal_id, idempotency_key" unique), even though the *general*
// idempotency mechanism now also lives in `idempotency_records`
// (SS000190) for the other 13 operations. Checkout keeps its own
// long-standing column + constraint because `sale.idempotency_key` is
// itself the resource being looked up on retry (invariant #5) -- moving
// it to the generic table would work equally well, but duplicating the
// simpler, already-frozen invariant #5 wording here avoids a Stage 6
// migration of meaning; `idempotency_records` is additionally used for
// CHECKOUT too, for a single consistent lookup path across all 14
// operations (see idempotency_records' own migration note) -- the two
// are not in conflict, `sales.idempotency_key` is a convenience
// short-circuit and remains authoritative for "does this sale already
// exist for this key", exactly as invariant #5 states.
//
// transaction_number uniqueness scope (Stage 5 instruction SS64, not
// specified by the frozen corpus): unique per store, disclosed as a
// Stage 5 decision, not a quoted frozen rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_day_id')->constrained('fiscal_days')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('transaction_number');
            $table->timestampTz('sold_at');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('order_level_discount_amount', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('taxable_sales', 12, 2)->default(0);
            $table->decimal('vat_exempt_sales', 12, 2)->default(0);
            $table->decimal('zero_rated_sales', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->string('status')->default('COMPLETED'); // COMPLETED|VOIDED|PARTIALLY_REFUNDED|REFUNDED -- no DRAFT
            $table->uuid('idempotency_key')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('buyer_tin')->nullable();
            $table->string('buyer_business_style')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['store_id', 'transaction_number']);
            // Owner hardening pass follow-up, 2026-09-16: referenced side
            // for invoices' composite FK proving an invoice's sale
            // belongs to the same store as the invoice_series it drew
            // its serial from -- see the invoices migration.
            $table->unique(['store_id', 'id']);
            // Owner hardening pass follow-up #2, 2026-09-16: referenced
            // side for invoices' (store_id, terminal_id, sale_id)
            // composite FK, which proves an invoice's own terminal_id
            // matches the terminal that actually finalized the sale it
            // documents -- not just that both terminals happen to
            // belong to the same store. See the invoices migration and
            // context-integrity-matrix.md.
            $table->unique(['store_id', 'terminal_id', 'id']);
        });

        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('COMPLETED','VOIDED','PARTIALLY_REFUNDED','REFUNDED'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_subtotal_nonneg_check CHECK (subtotal >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_grand_total_nonneg_check CHECK (grand_total >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_order_discount_nonneg_check CHECK (order_level_discount_amount >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_discount_total_nonneg_check CHECK (discount_total >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_taxable_sales_nonneg_check CHECK (taxable_sales >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_vat_exempt_sales_nonneg_check CHECK (vat_exempt_sales >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_zero_rated_sales_nonneg_check CHECK (zero_rated_sales >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_vat_amount_nonneg_check CHECK (vat_amount >= 0)');
        // Invariant #5 -- idempotent finalization, terminal-scoped
        // (ADR-010, corrected from store-scoped during Stage 3).
        DB::statement('CREATE UNIQUE INDEX sales_idempotency_unique ON sales (terminal_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        // Owner hardening pass, 2026-09-16, closing DB-INV-063: four
        // independent single-column FKs (store_id, terminal_id,
        // shift_id, fiscal_day_id) previously only proved each referenced
        // row existed -- nothing stopped a structurally legal but
        // impossible combination like store A's sale citing store B's
        // shift, or terminal A's sale citing terminal B's fiscal_day.
        // These three composite FKs make that combination a database
        // constraint violation instead. See context-integrity-matrix.md.
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_store_terminal_fk FOREIGN KEY (store_id, terminal_id) REFERENCES terminals (store_id, id)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_terminal_shift_fk FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts (terminal_id, id)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_terminal_fiscal_day_fk FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

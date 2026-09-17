<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// index-strategy.md's full rationale lives in docs/04-database/. This
// migration exists as its own step (rather than inline on each CREATE
// TABLE) because these indexes are driven by architecture.md SS20's
// reporting-access-pattern table and Stage 5 instruction SS42/SS48/SS50 --
// grouping them here keeps "why does this index exist" traceable to one
// place instead of scattered justifications repeated across 15+ files.
//
// PostgreSQL does NOT automatically index a foreign-key column (Stage 5
// instruction SS50) -- every index below that looks like "just the FK
// again" is deliberate, not redundant.
return new class extends Migration
{
    public function up(): void
    {
        // --- Sales reporting (architecture.md SS20) ---
        DB::statement("CREATE INDEX sales_store_sold_at_completed_idx ON sales (store_id, sold_at) WHERE status = 'COMPLETED'");
        DB::statement('CREATE INDEX sales_cashier_sold_at_idx ON sales (cashier_id, sold_at)');
        DB::statement('CREATE INDEX sales_terminal_sold_at_idx ON sales (terminal_id, sold_at)');
        DB::statement('CREATE INDEX sales_fiscal_day_idx ON sales (fiscal_day_id)');
        DB::statement('CREATE INDEX sales_shift_idx ON sales (shift_id)');
        DB::statement('CREATE INDEX sales_invoice_number_lookup_idx ON sales (store_id, transaction_number)');

        // --- Sale items / payments (FK-driven joins, architecture.md SS20) ---
        DB::statement('CREATE INDEX sale_items_sale_idx ON sale_items (sale_id)');
        DB::statement('CREATE INDEX sale_items_product_idx ON sale_items (product_id)');
        DB::statement('CREATE INDEX payments_sale_method_idx ON payments (sale_id, method)');

        // --- Catalog (products.category_id/brand_id are FKs; no auto-index) ---
        DB::statement('CREATE INDEX products_category_idx ON products (category_id)');
        DB::statement('CREATE INDEX products_brand_idx ON products (brand_id)');
        DB::statement('CREATE INDEX products_store_active_idx ON products (store_id, active)');
        DB::statement('CREATE INDEX product_barcodes_product_idx ON product_barcodes (product_id)');

        // --- Invoices (direct invoice_number lookup, independent of series) ---
        DB::statement('CREATE INDEX invoices_invoice_number_idx ON invoices (invoice_number)');
        DB::statement('CREATE INDEX invoices_terminal_idx ON invoices (terminal_id)');
        DB::statement('CREATE INDEX invoices_fiscal_installation_idx ON invoices (fiscal_installation_id)');

        // --- Void / Refund approval workflow (Stage 5 instruction SS42:
        //     "efficiently locate pending Void requests, pending Refund
        //     requests, RefundItems by SaleItem, existing finalized
        //     Refunds by Sale" -- backs voidList/refundList?status=REQUESTED
        //     from openapi.yaml SS12.1). ---
        DB::statement('CREATE INDEX voids_sale_idx ON voids (sale_id)');
        DB::statement('CREATE INDEX voids_status_requested_at_idx ON voids (status, requested_at)');
        DB::statement('CREATE INDEX refunds_sale_idx ON refunds (sale_id)');
        DB::statement('CREATE INDEX refunds_status_idx ON refunds (status, requested_at)');
        // "existing finalized Refunds by Sale" specifically, for the
        // cumulative-cap re-check under lock (architecture.md SS24
        // "Refund racing another refund").
        DB::statement("CREATE INDEX refunds_sale_completed_idx ON refunds (sale_id) WHERE status = 'COMPLETED'");
        DB::statement('CREATE INDEX refund_items_refund_idx ON refund_items (refund_id)');
        DB::statement('CREATE INDEX refund_items_sale_item_idx ON refund_items (sale_item_id)');
        DB::statement('CREATE INDEX refund_settlements_refund_idx ON refund_settlements (refund_id)');

        // --- Inventory (architecture.md SS20) ---
        DB::statement('CREATE INDEX stock_balances_location_product_idx ON stock_balances (location_id, product_id)');

        // --- Shifts / Fiscal Days (architecture.md SS20) ---
        DB::statement('CREATE INDEX shifts_terminal_opened_at_idx ON shifts (terminal_id, opened_at)');
        DB::statement('CREATE INDEX shifts_cashier_opened_at_idx ON shifts (cashier_id, opened_at)');
        DB::statement('CREATE INDEX shifts_fiscal_day_idx ON shifts (fiscal_day_id)');
        DB::statement('CREATE INDEX fiscal_days_terminal_business_date_idx ON fiscal_days (terminal_id, business_date)');
        DB::statement('CREATE INDEX cash_movements_shift_idx ON cash_movements (shift_id)');
        DB::statement('CREATE INDEX x_readings_shift_idx ON x_readings (shift_id)');
        DB::statement('CREATE INDEX x_readings_terminal_idx ON x_readings (terminal_id)');
        DB::statement('CREATE INDEX z_readings_terminal_idx ON z_readings (terminal_id)');

        // --- Terminal / fiscal identity lookups ---
        DB::statement('CREATE INDEX terminals_store_status_idx ON terminals (store_id, status)');
        DB::statement('CREATE INDEX terminal_enrollment_tokens_terminal_idx ON terminal_enrollment_tokens (terminal_id)');
        DB::statement('CREATE INDEX fiscal_installations_store_idx ON fiscal_installations (store_id)');
        DB::statement('CREATE INDEX terminal_fiscal_installations_installation_idx ON terminal_fiscal_installations (fiscal_installation_id)');
        DB::statement('CREATE INDEX tax_registrations_store_idx ON tax_registrations (store_id)');

        // --- Users ---
        DB::statement('CREATE INDEX users_store_idx ON users (store_id)');

        // --- Idempotency: lookups by terminal are already covered by the
        //     UNIQUE(terminal_id, idempotency_key) index itself. ---
    }

    public function down(): void
    {
        // Dropping an index (unlike dropping a column or table) never
        // loses data, so -- unlike most down() methods in this schema --
        // this one is safe to run automatically (Stage 5 instruction
        // SS56).
        $indexes = [
            'sales_store_sold_at_completed_idx', 'sales_cashier_sold_at_idx',
            'sales_terminal_sold_at_idx', 'sales_fiscal_day_idx', 'sales_shift_idx',
            'sales_invoice_number_lookup_idx', 'sale_items_sale_idx', 'sale_items_product_idx',
            'payments_sale_method_idx', 'products_category_idx', 'products_brand_idx',
            'products_store_active_idx', 'product_barcodes_product_idx', 'invoices_invoice_number_idx',
            'invoices_terminal_idx', 'invoices_fiscal_installation_idx', 'voids_sale_idx',
            'voids_status_requested_at_idx', 'refunds_sale_idx', 'refunds_status_idx',
            'refunds_sale_completed_idx', 'refund_items_refund_idx', 'refund_items_sale_item_idx',
            'refund_settlements_refund_idx', 'stock_balances_location_product_idx',
            'shifts_terminal_opened_at_idx', 'shifts_cashier_opened_at_idx', 'shifts_fiscal_day_idx',
            'fiscal_days_terminal_business_date_idx', 'cash_movements_shift_idx',
            'x_readings_shift_idx', 'x_readings_terminal_idx', 'z_readings_terminal_idx',
            'terminals_store_status_idx', 'terminal_enrollment_tokens_terminal_idx',
            'fiscal_installations_store_idx', 'terminal_fiscal_installations_installation_idx',
            'tax_registrations_store_idx', 'users_store_idx',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};

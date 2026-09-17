<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 5 amendment, 2026-09-17 -- closes the NON_VAT frozen-corpus gap
// discovered while implementing Stage 6A's FinancialCalculator (see
// domain-model.md SS4a and invariants.md TAX-NV-001..005). Not part of the
// original Stage 5 migration set -- disclosed as a separate, additive
// migration rather than editing the already-frozen
// 2026_01_01_000200_create_sales_table.php.
//
// TAX-NV-002/003: a VAT-registered sale always has non_vat_sales = 0.00;
// a NON_VAT-registered sale always has taxable_sales = vat_exempt_sales =
// zero_rated_sales = vat_amount = 0.00 and non_vat_sales = grand_total.
// These two groups are mutually exclusive by construction -- there is no
// domain rule (Stage 2 TaxRegistration is store-level, effective-dated,
// and a Sale is finalized atomically under exactly one registration) that
// allows both to be nonzero on the same row, so the mutual-exclusivity
// CHECK below enforces the frozen V1 rule exactly, matching the existing
// per-column nonneg CHECK style established in the sales migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('non_vat_sales', 12, 2)->default(0)->after('vat_amount');
        });

        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_non_vat_sales_nonneg_check CHECK (non_vat_sales >= 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE sales ADD CONSTRAINT sales_non_vat_vat_mutually_exclusive_check CHECK (
                non_vat_sales = 0
                OR (taxable_sales = 0 AND vat_exempt_sales = 0 AND zero_rated_sales = 0 AND vat_amount = 0)
            )
            SQL);
    }

    public function down(): void
    {
        // PostgreSQL drops sales_non_vat_sales_nonneg_check and
        // sales_non_vat_vat_mutually_exclusive_check automatically when
        // the column they depend on is dropped.
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('non_vat_sales');
        });
    }
};

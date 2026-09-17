<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 TAX_REGISTRATION -- effective-dated history of a
// store's tax registration. Invariant #54: a store's tax registration is
// never silently defaulted (no active row = finalization must fail, a
// Stage 6 application check, not a schema one). Required invariant here
// (Stage 5 instruction SS12): a store cannot have overlapping effective
// registration periods -- enforced by the partial unique index below,
// which guarantees at most one row with effective_to IS NULL ("current")
// per store; a genuinely overlapping *closed* interval is a
// transactional/application concern (Stage 6), since PostgreSQL has no
// declarative "no overlapping date ranges across rows" constraint without
// the btree_gist extension, which this design deliberately avoids adding
// (Stage 5 instruction SS66 -- avoid unnecessary extensions).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('registration_type'); // VAT|NON_VAT
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE tax_registrations ADD CONSTRAINT tax_registrations_type_check CHECK (registration_type IN ('VAT','NON_VAT'))");
        DB::statement('ALTER TABLE tax_registrations ADD CONSTRAINT tax_registrations_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement('CREATE UNIQUE INDEX tax_registrations_one_current_per_store ON tax_registrations (store_id) WHERE effective_to IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_registrations');
    }
};

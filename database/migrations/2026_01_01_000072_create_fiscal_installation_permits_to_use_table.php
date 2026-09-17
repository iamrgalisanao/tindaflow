<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-009 -- Permit to Use identity/status, independently effective-dated
// from Accreditation (see the sibling
// fiscal_installation_accreditations table). RMC 72-2025 (BIR-012):
// a taxpayer's PTU does not automatically expire solely because the
// software's Certificate of Accreditation expires -- these two lifecycles
// can change on different schedules, which is exactly why they are two
// tables, not one. `min` (Machine Identification Number) is a
// PTU-lifecycle concept per domain-model.md SS2.1, not an
// accreditation-lifecycle one -- it lives here, not on the accreditation
// table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_installation_permits_to_use', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiscal_installation_id')->constrained('fiscal_installations')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('min')->nullable();
            $table->date('date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX fiscal_installation_ptu_one_current ON fiscal_installation_permits_to_use (fiscal_installation_id) WHERE effective_to IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_installation_permits_to_use');
    }
};

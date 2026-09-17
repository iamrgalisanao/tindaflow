<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-009 -- Accreditation identity/status/validity, independently
// effective-dated from Permit to Use (see the sibling
// fiscal_installation_permits_to_use table). A new accreditation event
// (e.g. a major-enhancement re-accreditation, RMO 24-2023) inserts a new
// row and closes the previous one; it never mutates a historical row's
// number/date in place.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_installation_accreditations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiscal_installation_id')->constrained('fiscal_installations')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->date('date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX fiscal_installation_accreditations_one_current ON fiscal_installation_accreditations (fiscal_installation_id) WHERE effective_to IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_installation_accreditations');
    }
};

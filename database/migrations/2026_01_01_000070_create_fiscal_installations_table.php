<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 FISCAL_INSTALLATION -- store-scoped (not
// terminal-scoped), representing an accredited software/hardware
// installation. ADR-009: Accreditation and Permit-to-Use identity/status
// are independently effective-dated lifecycles, NOT collapsed into this
// row's own installed_at/superseded_at pair (which governs the
// installation identity itself -- software_version/machine_serial_number/
// deployment_model). See the two child history tables created next
// (fiscal_installation_accreditations, fiscal_installation_permits_to_use)
// for the Stage 5 schema-shape decision ADR-009 explicitly deferred:
// "two column-pairs vs two child tables". This design chooses child
// tables, per Stage 5 instruction SS11's preference, for cleaner temporal
// integrity -- a real accreditation or PTU renewal history, not just a
// single current+previous pair.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('deployment_model'); // STANDALONE|SERVER_CONNECTED
            $table->string('machine_serial_number')->nullable();
            $table->string('software_version');
            $table->timestampTz('installed_at');
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();

            // UNIQUE(store_id, id) (owner hardening pass, 2026-09-16):
            // lets terminal_fiscal_installations composite-FK against
            // (store_id, fiscal_installation_id), proving a terminal can
            // never be associated with another store's installation --
            // see context-integrity-matrix.md.
            $table->unique(['store_id', 'id']);
        });

        DB::statement("ALTER TABLE fiscal_installations ADD CONSTRAINT fiscal_installations_deployment_model_check CHECK (deployment_model IN ('STANDALONE','SERVER_CONNECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_installations');
    }
};

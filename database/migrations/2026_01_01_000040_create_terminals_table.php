<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 TERMINAL -- a physical or logical POS station.
// ADR-011: a terminal's identity is a server-issued credential, never a
// bare client-supplied value. This migration adds credential_hash/
// credential_issued_at/revoked_at to carry that credential's storage
// (ADR-011 SS"Revocation") -- Stage 5 instruction SS9 is explicit: do not
// store raw terminal credentials, persist only secure hashes/identifiers.
//
// UNIQUE(store_id, id) (owner hardening pass, 2026-09-16): exists solely
// so downstream tables can composite-FK against (store_id, terminal_id)
// and have PostgreSQL itself prove the referenced terminal really
// belongs to the declared store -- closing DB-INV-063 (see
// constraint-register.md and context-integrity-matrix.md). `id` alone is
// already the primary key; this is an additional index over the same
// column plus store_id, required because a composite FK's referenced
// side must have a unique constraint on exactly that column tuple.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('terminal_code');
            $table->string('status')->default('ACTIVE'); // ACTIVE|INACTIVE|DECOMMISSIONED
            $table->timestampTz('activated_at')->nullable();

            // ADR-011: server-issued credential, never a raw/plaintext secret.
            $table->string('credential_hash')->nullable();
            $table->timestampTz('credential_issued_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->unique(['store_id', 'terminal_code']);
            $table->unique(['store_id', 'id']);
        });

        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_status_check CHECK (status IN ('ACTIVE','INACTIVE','DECOMMISSIONED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};

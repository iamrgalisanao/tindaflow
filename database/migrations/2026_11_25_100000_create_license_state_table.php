<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-018 decisions 2 and 3. license_state  Table kind: LIFECYCLE, one row per installation, MUTABLE BY DESIGN (the heartbeat updates it every
// minute), so it is deliberately NOT in the append-only list. The single row (id = 1, enforced by a CHECK) holds the trusted-time high-water mark,
// the heartbeat bookkeeping that lets it advance when the wall clock is rolled back, the current activation certificate once there is one, and the
// two verdicts the control plane can hand down (revoked, chain invalid). Nothing here is read or written inside the checkout transaction.
// Certificate history, when it arrives, is a separate append-only table. A host administrator who edits this row is the accepted residual risk of ADR-018.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_state', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->text('certificate')->nullable();
            $table->unsignedBigInteger('certificate_seq')->nullable();
            $table->timestampTz('trusted_time_hwm')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->string('heartbeat_boot_id', 64)->nullable();
            $table->unsignedBigInteger('heartbeat_uptime_seconds')->nullable();
            $table->boolean('revoked')->default(false);
            $table->boolean('chain_invalid')->default(false);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE license_state ADD CONSTRAINT license_state_singleton_check CHECK (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('license_state');
    }
};

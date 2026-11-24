<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-018 decisions 1, 5 and 8. license_state (mutable by design, see its own migration) gains what the renewal client needs between runs:
// the control plane URLs the bundle named (primary first), the client-generated request id of a renewal that was sent but not yet answered
// (kept so a retry is the SAME request and the provider can hand back the stored successor instead of forking the chain), and the outcome
// of the last attempt for the status screen and the pilot log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_state', function (Blueprint $table) {
            $table->jsonb('control_plane')->nullable();
            $table->uuid('pending_request_id')->nullable();
            $table->timestampTz('last_renewal_attempt_at')->nullable();
            $table->timestampTz('last_renewed_at')->nullable();
            $table->string('last_renewal_error', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('license_state', function (Blueprint $table) {
            $table->dropColumn(['control_plane', 'pending_request_id', 'last_renewal_attempt_at', 'last_renewed_at', 'last_renewal_error']);
        });
    }
};

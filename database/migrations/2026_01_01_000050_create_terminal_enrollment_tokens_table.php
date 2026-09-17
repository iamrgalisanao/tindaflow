<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 enrollment flow / Stage 5 instruction SS10. Tokens are one-time,
// expiring, stored hashed, non-recoverable after issuance -- only
// token_hash is persisted, never the plaintext token (the plaintext is
// returned exactly once in the API response, per openapi.yaml
// TerminalEnrollmentToken, and never stored anywhere).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_enrollment_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->string('token_hash');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // A token is looked up by its hash at redemption time.
            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_enrollment_tokens');
    }
};

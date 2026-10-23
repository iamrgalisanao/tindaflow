<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-015: remote till approval. A signed-in person at an un-enrolled browser asks to become a till; an administrator elsewhere
// approves the request by typing the short code shown on that browser; the browser then claims its terminal credential. Both
// the code and the claim secret are stored hashed, so neither can be read back from the database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_pairing_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('code_hash');
            $table->string('secret_hash');
            $table->string('status')->default('PENDING');
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['store_id', 'status', 'expires_at']);
        });

        DB::statement("ALTER TABLE terminal_pairing_requests ADD CONSTRAINT terminal_pairing_requests_status_check CHECK (status IN ('PENDING','APPROVED','REJECTED','CONSUMED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_pairing_requests');
    }
};

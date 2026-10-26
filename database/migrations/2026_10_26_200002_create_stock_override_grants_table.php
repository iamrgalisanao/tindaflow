<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-017 section 9 (slice A) / invariants.md STK-005. The approval of ONE blocked sale: a manager typed their own code on the cashier's
// till and the server issued a single-use token bound to the terminal, the cashier and the exact shortfall.
//
// stock_override_grants  Table kind: APPEND-ONLY except two columns. `demand_hash` is the SHA-256 of the canonical sorted shortfall
//   (product id and quantity required of every short line), so changing the cart invalidates the approval; `shortfalls` keeps the same lines
//   readable (name, required, available at approval time) for the audit trail and the later daily report. Only a SHA-256 of the random
//   token is stored. The application role may UPDATE `consumed_at` and `sale_id` and nothing else: checkout stamps both in the transaction
//   of the sale that uses the grant, and only when a shortfall really exists under the stock locks. `sale_id` set means "this sale was
//   oversold on an approved override"; it is UNIQUE, so one grant can never cover two sales. A rolled-back sale leaves the grant unused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_override_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approver_id')->constrained('users')->restrictOnDelete();
            $table->char('demand_hash', 64);
            $table->jsonb('shortfalls');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignUuid('sale_id')->nullable()->unique()->constrained('sales')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['terminal_id', 'created_at']);
        });
        DB::statement('ALTER TABLE stock_override_grants ADD CONSTRAINT stock_override_grants_cashier_not_approver_check CHECK (cashier_id <> approver_id)');
        DB::statement('ALTER TABLE stock_override_grants ADD CONSTRAINT stock_override_grants_expiry_check CHECK (expires_at > created_at)');
        DB::statement('ALTER TABLE stock_override_grants ADD CONSTRAINT stock_override_grants_consumed_with_sale_check CHECK ((consumed_at IS NULL) = (sale_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_override_grants');
    }
};

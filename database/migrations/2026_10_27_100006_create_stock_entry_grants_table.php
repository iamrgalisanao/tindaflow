<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-017 slice B, client decision 5 (2026-10-27) / invariants.md STK-007. The approval of ONE daily stock entry by a cashier at the till: a
// manager typed their own code and the server issued a short-lived single-use token bound to the terminal, the cashier, the store and the
// stock date.
//
// stock_entry_grants  Table kind: APPEND-ONLY except one column. Only a SHA-256 of the random token is stored. The application role may UPDATE
//   `consumed_at` and nothing else: the entry POST stamps it, under the grant's row lock, in the transaction that writes the movements, so
//   a rolled-back entry leaves the grant unused and a used one can never be revived. Never deleted (the store-wide REVOKE DELETE). The
//   terminal, cashier, approver, stock date and expiry can never be changed, so an approval cannot be re-pointed at another day or till.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_entry_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approver_id')->constrained('users')->restrictOnDelete();
            $table->date('stock_date');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['terminal_id', 'created_at']);
        });
        DB::statement('ALTER TABLE stock_entry_grants ADD CONSTRAINT stock_entry_grants_cashier_not_approver_check CHECK (cashier_id <> approver_id)');
        DB::statement('ALTER TABLE stock_entry_grants ADD CONSTRAINT stock_entry_grants_expiry_check CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_entry_grants');
    }
};

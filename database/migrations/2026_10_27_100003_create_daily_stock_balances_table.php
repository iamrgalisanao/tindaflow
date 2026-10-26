<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-017 section 3 (slice B). daily_stock_balances  Table kind: DERIVED CACHE / LIFECYCLE, exactly like stock_balances (domain-model SS2.4,
// invariant #44): quantity_on_hand is a projection of the ledger, the signed sum of the stock_movements of this (product, location, stock_date).
// It is changed ONLY by StockLedger, as the atomic side effect of inserting a movement in the same transaction
// (`INSERT ... ON CONFLICT DO UPDATE SET qty = qty + delta`). The application role keeps INSERT and UPDATE and has no DELETE (the store-wide
// REVOKE DELETE); it is deliberately NOT in the append-only list. `inventory:rebuild-balances` recomputes it from the ledger.
// Daily stock is keyed by the stock date it belongs to, so yesterday's portions simply do not count today and no job runs at midnight. A negative
// quantity is legal (an approved override, or blocking switched off) and is the "oversold" figure of the daily report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stock_balances', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('stock_date');
            $table->decimal('quantity_on_hand', 10, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['product_id', 'location_id', 'stock_date']);
            $table->index(['stock_date', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stock_balances');
    }
};

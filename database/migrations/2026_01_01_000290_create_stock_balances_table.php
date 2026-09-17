<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.4 STOCK_BALANCE -- derived, NEVER authoritative
// (invariant #44): quantity_on_hand must always equal the signed sum of
// stock_movement.quantity for the same (product_id, location_id).
// erd.md: "no primary key of its own beyond (product_id, location_id)"
// -- composite PK, no surrogate id, since this row has no independent
// identity beyond that pair (Stage 5 instruction SS14: document that
// this is derived/cached state with a clear consistency model).
// Consistency model: updated only as an atomic side-effect of inserting
// a stock_movement row, in the SAME transaction (architecture.md SS4) --
// never written to independently. This is documented, not enforced by a
// trigger (Stage 5 instruction SS3: triggers require explicit
// justification, and none exists here -- Stage 6's application code
// performs both inserts in one transaction).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity_on_hand', 10, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.9 REFUND_SETTLEMENT -- deliberately carries no
// terminal_id/shift_id/processed_by of its own (inherits processing
// context from the parent `refund`, domain-model.md SS2.9's explicit
// "deliberately not duplicated" note). SUM(amount) = refund.refund_total
// (invariant #70) is a TRANSACTIONAL invariant (Stage 6, checked at the
// same APPROVED->COMPLETED transition that creates these rows) --
// Stage 5 instruction SS33 explicitly warns against a fragile
// cross-row-SUM CHECK/trigger; recorded in constraint-register.md
// instead.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->string('payment_method'); // CASH|GCASH|MAYA|CARD|OTHER
            $table->decimal('amount', 12, 2);
            $table->timestampTz('processed_at');
            $table->string('external_reference')->nullable();
        });

        DB::statement("ALTER TABLE refund_settlements ADD CONSTRAINT refund_settlements_method_check CHECK (payment_method IN ('CASH','GCASH','MAYA','CARD','OTHER'))");
        DB::statement('ALTER TABLE refund_settlements ADD CONSTRAINT refund_settlements_amount_positive_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_settlements');
    }
};

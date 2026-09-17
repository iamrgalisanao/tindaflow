<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.5 CASH_MOVEMENT -- append-only financial record
// (Stage 5 instruction SS34). No updated_at: nothing about a recorded
// cash movement is ever mutated (invariant SS"Shift" #38's "never
// corrected away" principle applied here too).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('type'); // CASH_IN|CASH_OUT
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignUuid('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (type IN ('CASH_IN','CASH_OUT'))");
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};

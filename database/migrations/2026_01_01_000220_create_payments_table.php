<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.7 PAYMENT -- Stage 5 instruction SS25: no sensitive
// card PAN/CVV is ever persisted; reference_note is a free-text nullable
// note (e.g. a GCash confirmation number), never a card number.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->string('method'); // CASH|GCASH|MAYA|CARD|OTHER
            $table->decimal('amount', 12, 2);
            $table->string('reference_note')->nullable();
            $table->timestampTz('recorded_at');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('CASH','GCASH','MAYA','CARD','OTHER'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

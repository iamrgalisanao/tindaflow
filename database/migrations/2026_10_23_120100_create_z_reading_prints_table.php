<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Z-reading's ORIGINAL printout is made once. z_readings is append-only (the application role cannot UPDATE it), so "the original was
// printed" is recorded here, insert-only, and the unique constraint decides a race: one insert wins and is the original, every other
// print is a marked copy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('z_reading_prints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('z_reading_id')->constrained('z_readings')->restrictOnDelete();
            $table->foreignUuid('printed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('printed_at')->useCurrent();

            $table->unique('z_reading_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('z_reading_prints');
    }
};

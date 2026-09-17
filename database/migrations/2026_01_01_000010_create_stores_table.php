<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 STORE -- the business entity operating TindaFlow.
// V1 is single-store; every table below is store_id-scoped so multi-store
// (Phase 2) is additive, per the frozen domain model's own framing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

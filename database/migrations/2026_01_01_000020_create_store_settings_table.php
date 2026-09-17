<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 STORE_SETTINGS -- 1:1 with store. Business identity
// fields for Module B. Holds NO tax-registration or per-terminal fiscal
// data -- those live in their own effective-dated entities (tax_registrations,
// fiscal_installations) specifically so a change to one never touches this
// table (domain-model.md SS2.1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            // 1:1 with store -- store_id is both PK and FK, no separate id.
            $table->uuid('store_id')->primary();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();

            $table->string('business_name');
            $table->string('registered_name');
            $table->string('business_address')->nullable();
            $table->string('tin');
            $table->string('branch_code')->nullable();
            $table->text('invoice_header')->nullable();
            $table->text('invoice_footer')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};

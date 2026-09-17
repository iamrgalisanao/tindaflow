<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.2 USER -- store_id, name, email/username, password_hash,
// role (fixed ADMIN|MANAGER|CASHIER), active. No dynamic role/permission
// tables exist in V1 (domain-model.md SS2.2) -- capability evaluation is
// application/policy logic (Stage 6), not a database concept; this table
// stores only the fixed role.
//
// Column naming: the frozen domain names the column `password_hash`
// (domain-model.md SS2.2) -- kept verbatim rather than Laravel's
// conventional `password`, since Stage 6's Eloquent model can override
// getAuthPasswordName() to point Laravel's auth guard at this exact column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password_hash');
            $table->string('role'); // CHECK constraint added below: ADMIN|MANAGER|CASHIER
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestampsTz();

            // Login identifier is unique per store, not globally -- V1 is
            // single-store so this is equivalent to global uniqueness in
            // practice, but store-scoping is what the frozen model's
            // "every table is store_id-scoped" principle implies
            // (domain-model.md SS2.1) and keeps multi-store additive later.
            $table->unique(['store_id', 'email']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','MANAGER','CASHIER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

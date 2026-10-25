<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 6 / invariants.md OVR-001, OVR-002. The supervisor override of the credit limit: the approver's code and the
// single-use grants a successful code check issues.
//
// user_override_codes  Table kind: LIFECYCLE. One row per approver (a MANAGER or ADMIN who holds CREDIT_LIMIT_OVERRIDE). `code_hash` is a
//   slow hash (the framework hasher) of a 6 to 8 digit code and is NULL after an administrator resets the code, so a reset never reveals or
//   chooses a code. `failed_attempts` counts wrong codes since the last good one; the fifth wrong code sets `locked_until` (15 minutes) and
//   zeroes the counter. Never deleted. The application role gets a COLUMN-LEVEL UPDATE (code_hash, failed_attempts, locked_until, set_at,
//   set_by, updated_at) that leaves out `user_id` and `store_id`; the history of sets, resets and lockouts is in the append-only audit log.
//
// credit_override_grants  Table kind: APPEND-ONLY except one column. A row is the approval of ONE over-limit credit sale: the cashier, the
//   terminal, the employee and the most (`max_amount`) the sale may charge, valid until `expires_at`. Only a SHA-256 of the random token is
//   stored (the token itself has 256 bits, so a fast hash is enough); the token is shown once, to the till that asked. The application
//   role may UPDATE `consumed_at` and nothing else; checkout sets it, under the employee's row lock, in the transaction of the sale that
//   uses the grant, so a rolled-back sale leaves the grant unused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_override_codes', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code_hash')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('set_at')->nullable();
            $table->foreignUuid('set_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('store_id');
        });
        DB::statement('ALTER TABLE user_override_codes ADD CONSTRAINT user_override_codes_attempts_check CHECK (failed_attempts >= 0)');

        Schema::create('credit_override_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approver_id')->constrained('users')->restrictOnDelete();
            $table->decimal('max_amount', 12, 2);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'created_at']);
        });
        DB::statement('ALTER TABLE credit_override_grants ADD CONSTRAINT credit_override_grants_amount_check CHECK (max_amount > 0)');
        DB::statement('ALTER TABLE credit_override_grants ADD CONSTRAINT credit_override_grants_cashier_not_approver_check CHECK (cashier_id <> approver_id)');
        DB::statement('ALTER TABLE credit_override_grants ADD CONSTRAINT credit_override_grants_expiry_check CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_override_grants');
        Schema::dropIfExists('user_override_codes');
    }
};

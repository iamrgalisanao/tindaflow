<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 5 / slice 3.2: the employee a credit sale was charged to, on the sale itself and not in a note. Null for every
// other sale. Table kind: sales is APPEND-ONLY apart from `status` (void and refund); employee_id is written once at INSERT and sits
// under the table-wide UPDATE revoke, so no grant changes. The FK is RESTRICT: an employee row is never deleted (it is deactivated).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('employee_id')->nullable()->after('cashier_id')->constrained('employees')->restrictOnDelete();
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};

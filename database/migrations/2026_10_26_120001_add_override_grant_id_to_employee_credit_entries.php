<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 5 / invariants.md OVR-001. A CHARGE that went past the limit names the override grant that allowed it, so any
// over-limit charge links to its approver. employee_credit_entries stays APPEND-ONLY: this is a nullable column added to an empty-or-
// existing table (existing rows stay NULL, which is true: none was an override), written only by the INSERT of the CHARGE. The
// application role has no UPDATE on the table, so the link cannot be added or changed later. A grant is referenced by at most one
// entry (partial unique index), which is the database's own backstop against one approval being used for two sales.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_credit_entries', function (Blueprint $table) {
            $table->foreignUuid('override_grant_id')->nullable()->constrained('credit_override_grants')->restrictOnDelete();
            $table->unique('override_grant_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_credit_entries', function (Blueprint $table) {
            $table->dropUnique(['override_grant_id']);
            $table->dropConstrainedForeignId('override_grant_id');
        });
    }
};

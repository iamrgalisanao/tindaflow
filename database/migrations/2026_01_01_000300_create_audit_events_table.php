<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.10 AUDIT_EVENT -- the broad security/operations log.
// Append-only, structurally (invariant #48): the application's PostgreSQL
// role has no UPDATE/DELETE grant on this table at all (see
// database/migrations/..._restrict_application_role_privileges.php,
// run last, and constraint-register.md) -- not merely "the app doesn't
// happen to do this."
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('event_type');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_metadata')->nullable();
            $table->jsonb('after_metadata')->nullable();
            $table->string('reason')->nullable();
            $table->string('request_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
        });

        $table = 'audit_events';
        DB::statement("CREATE INDEX {$table}_occurred_idx ON {$table} (occurred_at)");
        DB::statement("CREATE INDEX {$table}_entity_idx ON {$table} (entity_type, entity_id)");
        DB::statement("CREATE INDEX {$table}_event_type_idx ON {$table} (event_type)");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

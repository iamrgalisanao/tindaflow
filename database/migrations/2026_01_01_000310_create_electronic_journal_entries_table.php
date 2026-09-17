<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.10 ELECTRONIC_JOURNAL_ENTRY. Append-only, exactly
// one row per fiscally-journalable event (invariant #49) -- enforced by
// the UNIQUE(source_type, source_id, event_type) index below (ADR-005,
// architecture.md SS4).
//
// `id` type (disclosed resolution of an apparent frozen-text tension,
// not a contradiction): erd.md types this column `uuid`; ADR-005
// separately suggests "a bigserial or a ULID" specifically so entries
// sort chronologically without a second explicit ORDER BY key. A ULID IS
// a valid 128-bit UUID-format value that is also lexicographically
// sortable by creation time -- so this column stays a genuine `uuid`
// column (satisfying erd.md exactly) while Stage 6 generates its values
// as ULIDs, not random UUIDv4s (satisfying ADR-005's ordering intent).
// No schema change is needed to adopt this; it is purely how the
// application populates the column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->string('event_type'); // INVOICE|VOID|REFUND|X_READING|Z_READING|SHIFT_OPENED|SHIFT_CLOSED|CASH_IN|CASH_OUT|STOCK_ADJUSTED
            $table->string('source_type');
            $table->uuid('source_id');
            $table->foreignUuid('audit_event_id')->nullable()->constrained('audit_events')->restrictOnDelete();
            $table->jsonb('payload_json');
            $table->timestampTz('occurred_at')->useCurrent();
        });

        DB::statement("ALTER TABLE electronic_journal_entries ADD CONSTRAINT electronic_journal_entries_event_type_check CHECK (event_type IN ('INVOICE','VOID','REFUND','X_READING','Z_READING','SHIFT_OPENED','SHIFT_CLOSED','CASH_IN','CASH_OUT','STOCK_ADJUSTED'))");
        // Invariant #49 -- exactly one journal entry per fiscally-journalable event.
        DB::statement('CREATE UNIQUE INDEX electronic_journal_entries_source_unique ON electronic_journal_entries (source_type, source_id, event_type)');
        $table = 'electronic_journal_entries';
        DB::statement("CREATE INDEX {$table}_occurred_idx ON {$table} (occurred_at)");
        DB::statement("CREATE INDEX {$table}_event_type_idx ON {$table} (event_type)");
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_journal_entries');
    }
};

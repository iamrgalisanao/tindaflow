<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-010 -- generalized idempotency_record, now backing all 14 mandated
// operations (api-design.md SS5, Stage 4 pass 3) rather than columns on
// `sales` alone, since ADR-010 explicitly deferred "own table vs columns
// on sale" to Stage 5 and there are now 14 operation types across many
// resources, not one.
//
// Identifier strategy (disclosed dual-key justification, Stage 5
// instruction SS4): this table's `id` is a plain bigint, NOT a UUID, the
// one deliberate exception in this schema. Idempotency records are pure
// Stage 3/4 request-deduplication infrastructure -- they are never
// returned as, or referenced by, any API resource's own identifier
// (openapi.yaml exposes no "idempotency record" resource at all; a
// client only ever supplies the Idempotency-Key header it generated
// itself). A UUID PK would buy nothing here and cost an extra 12 bytes
// per row on what can be the highest-write-volume table in the system.
//
// Concurrency/failure-semantics design (Stage 5 instruction SS39,
// pass-5 remediation): the row for (terminal_id, idempotency_key) is
// INSERTed as the FIRST statement of the SAME database transaction that
// performs the mutating operation (never a separate, earlier
// transaction) -- see database-schema.md "Idempotency" section for the
// full state-transition table this design supports:
//   - request begins            -> INSERT (status=IN_PROGRESS) as step 1
//                                   of the operation's own transaction
//   - server crashes before commit -> the whole transaction, INSERT
//                                   included, is never committed;
//                                   PostgreSQL's crash recovery rolls it
//                                   back completely; the key is exactly
//                                   as if the request never happened
//   - operation succeeds        -> same transaction UPDATEs this row to
//                                   status=COMPLETED with result_type/
//                                   result_resource_id, atomically with
//                                   the business mutation, then commits
//   - operation fails (business -> the ENTIRE transaction rolls back,
//     rule rejection, no           INSERT included -- pass 5's rule
//     authoritative mutation)      ("failed approval is not automatic
//                                   rejection") is delivered for free by
//                                   transaction atomicity: the key is
//                                   fully available again, no cleanup
//                                   step needed
//   - response lost after commit -> row is COMPLETED and durable; a
//                                   retry with the same key+hash finds it
//                                   and returns the stored result
//                                   (ADR-010's behavior matrix)
//   - retry, same key+hash,      -> no row exists (prior attempt's
//     after a failed attempt        INSERT was rolled back) -> proceeds
//                                   as a fresh attempt; INSERT succeeds
//   - retry, same key, different -> only a conflict if a COMPLETED row
//     hash                          already exists for that key
//                                   (IDEMPOTENCY_KEY_REUSED, ADR-010); if
//                                   no row exists (prior attempt failed),
//                                   this is simply a fresh use of the key
//
// Concurrent duplicate submissions (double-click) are caught by
// PostgreSQL's own cross-transaction unique-index INSERT blocking: two
// simultaneous INSERTs for the same (terminal_id, idempotency_key) can
// never both succeed, exactly as architecture.md SS24 already documents
// for Checkout, generalized here to all 14 operations.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id(); // bigint -- see identifier-strategy note above
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('operation_type');
            $table->char('request_hash', 64); // SHA-256 hex digest
            $table->string('status')->default('IN_PROGRESS'); // IN_PROGRESS|COMPLETED
            $table->string('result_type')->nullable();
            $table->uuid('result_resource_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->unique(['terminal_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_status_check CHECK (status IN ('IN_PROGRESS','COMPLETED'))");
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_type_check CHECK (operation_type IN ('CHECKOUT','SALE_VOID','SALE_REFUND','VOID_APPROVE','VOID_REJECT','REFUND_APPROVE','REFUND_REJECT','SHIFT_OPEN','SHIFT_CLOSE','CASH_MOVEMENT','STOCK_RECEIPT','STOCK_ADJUSTMENT','FISCAL_DAY_CLOSE','INVOICE_REPRINT'))");
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_completed_check CHECK ((status = 'COMPLETED') = (completed_at IS NOT NULL AND result_resource_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};

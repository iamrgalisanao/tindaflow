<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Module A Decision Register (module-a-auth-terminal-initialization.md
// SS14, Ruling 3): terminals.credential_hash is looked up by direct
// equality on every POS_TERMINAL request, so two terminals sharing one
// hash would make ADR-011's trust boundary ("the server resolves
// terminal_id exclusively from this credential") ambiguous. The
// 2026_01_01_000040 terminals migration already declares this ADR-011
// intent (its own comment: "do not store raw terminal credentials,
// persist only secure hashes/identifiers") and its Stage 5 sibling,
// terminal_enrollment_tokens.token_hash, already carries the equivalent
// unique index -- this migration completes that same, already-declared
// Stage 5 intent, not a new invariant.
//
// A partial index (WHERE credential_hash IS NOT NULL) is required, not a
// plain unique constraint: every terminal is created with a NULL
// credential_hash before enrollment (ADR-011's 4-step flow), and
// Postgres unique constraints already treat multiple NULLs as
// non-conflicting -- the WHERE clause makes that pre-enrollment state
// explicit rather than relying on incidental NULL semantics.
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('terminals')
            ->select('credential_hash')
            ->whereNotNull('credential_hash')
            ->groupBy('credential_hash')
            ->havingRaw('count(*) > 1')
            ->pluck('credential_hash');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add terminals_credential_hash_unique: '.$duplicates->count()
                .' credential_hash value(s) are shared by more than one terminal. '.
                'This must be resolved manually (re-issue a fresh credential to every '.
                'terminal but one for each colliding hash) before this migration can '.
                'run -- it will not silently mutate, null, or pick a winner among them.'
            );
        }

        DB::statement('CREATE UNIQUE INDEX terminals_credential_hash_unique ON terminals (credential_hash) WHERE credential_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS terminals_credential_hash_unique');
    }
};

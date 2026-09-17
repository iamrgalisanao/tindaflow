<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.1 TERMINAL_FISCAL_INSTALLATION -- the join/history
// table resolving terminal<->installation without forcing a cardinality.
// A STANDALONE deployment produces one terminal <-> one installation over
// time (one current row per terminal); a SERVER_CONNECTED deployment can
// have several terminal_ids pointing at the same fiscal_installation_id
// CONCURRENTLY -- the partial unique index below enforces only "one
// CURRENT installation per terminal at a time", never "one terminal per
// installation", which is exactly the asymmetry both deployment models
// need (erd.md notes).
//
// store_id (owner hardening pass, 2026-09-16, closing DB-INV-063): a
// disclosed denormalized addition beyond erd.md's literal field list --
// added solely so this join row can composite-FK against BOTH
// terminals(store_id, id) and fiscal_installations(store_id, id),
// proving a terminal can never be associated with a fiscal_installation
// belonging to a different store, even though both underlying FKs
// individually only prove each referenced row exists. See
// context-integrity-matrix.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_fiscal_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_installation_id')->constrained('fiscal_installations')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX terminal_fiscal_installations_one_current_per_terminal ON terminal_fiscal_installations (terminal_id) WHERE effective_to IS NULL');
        // Owner hardening pass, 2026-09-16: proves the terminal and the
        // fiscal_installation being associated both genuinely belong to
        // the declared store.
        DB::statement('ALTER TABLE terminal_fiscal_installations ADD CONSTRAINT tfi_store_terminal_fk FOREIGN KEY (store_id, terminal_id) REFERENCES terminals (store_id, id)');
        DB::statement('ALTER TABLE terminal_fiscal_installations ADD CONSTRAINT tfi_store_installation_fk FOREIGN KEY (store_id, fiscal_installation_id) REFERENCES fiscal_installations (store_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_fiscal_installations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-016 section 7 / invariants.md SLIP-001. Which document a store gives for a sale: INVOICE (every existing store, the default and
// the unchanged path) or SLIP (internal sales monitoring; the store issues its own invoices outside TindaFlow).
//
//  - store_settings.sale_document_mode  the store's current mode. store_settings is a LIFECYCLE table (the application updates it), so
//    the mode can change; the change is held behind FISCAL_CONFIGURATION_MANAGE and refused while a shift or business day is open.
//    store_settings.slip_footer is the optional free-text line printed at the bottom of a slip (default empty, the store decides the
//    wording).
//  - sales.document_mode  the mode the sale was finalized in, written once at INSERT. sales is APPEND-ONLY apart from `status`
//    (harden_append_only_privileges.sql), so a store that later switches keeps an unambiguous history: a sale never changes document.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE store_settings ADD COLUMN sale_document_mode varchar(8) NOT NULL DEFAULT 'INVOICE'");
        DB::statement("ALTER TABLE store_settings ADD CONSTRAINT store_settings_sale_document_mode_check CHECK (sale_document_mode IN ('INVOICE', 'SLIP'))");
        DB::statement('ALTER TABLE store_settings ADD COLUMN slip_footer varchar(200) NULL');

        DB::statement("ALTER TABLE sales ADD COLUMN document_mode varchar(8) NOT NULL DEFAULT 'INVOICE'");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_document_mode_check CHECK (document_mode IN ('INVOICE', 'SLIP'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_document_mode_check');
        DB::statement('ALTER TABLE sales DROP COLUMN document_mode');
        DB::statement('ALTER TABLE store_settings DROP COLUMN slip_footer');
        DB::statement('ALTER TABLE store_settings DROP CONSTRAINT store_settings_sale_document_mode_check');
        DB::statement('ALTER TABLE store_settings DROP COLUMN sale_document_mode');
    }
};

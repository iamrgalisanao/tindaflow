<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-018 decisions 3 and 6. license_state (mutable by design) gains what offline codes need. `extended_until` is the expiry an applied
// extension gives the certificate of seq `extension_base_seq`, and counts only for that certificate. `last_code_ordinal` only ever grows,
// across certificates, so no code is applied twice. `ignored_host_stamp` is the hardware file stamp in force when the trusted-time mark was
// pulled back: the heartbeat must not use that one stamp to push the mark forward again before the helper writes a new file.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_state', function (Blueprint $table) {
            $table->timestampTz('extended_until')->nullable();
            $table->unsignedBigInteger('extension_base_seq')->nullable();
            $table->unsignedSmallInteger('extensions_in_a_row')->default(0);
            $table->unsignedInteger('last_code_ordinal')->default(0);
            $table->timestampTz('ignored_host_stamp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('license_state', function (Blueprint $table) {
            $table->dropColumn(['extended_until', 'extension_base_seq', 'extensions_in_a_row', 'last_code_ordinal', 'ignored_host_stamp']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared-credential detection (ADR-011's "reasonable future enhancement"): where and when a till was last used, and whether it has
// been used from two different computers in turn. A heuristic that raises a flag for an administrator, never a gate: a till keeps selling.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->timestampTz('last_seen_at')->nullable();
            $table->string('last_seen_ip', 64)->nullable();
            $table->string('last_seen_user_agent', 255)->nullable();
            $table->string('last_seen_signature', 64)->nullable();
            $table->string('previous_signature', 64)->nullable();
            $table->timestampTz('shared_use_suspected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'last_seen_ip', 'last_seen_user_agent', 'last_seen_signature', 'previous_signature', 'shared_use_suspected_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 1 / invariants.md MENU-005. Menu groups are the till's navigation tree: a top group (Lunch) and an optional
// sub-group under it (Standard Meal). They are separate from `categories`, which keep feeding the gross-profit-by-category report.
//
// Table kind: LIFECYCLE (configuration). The application role may INSERT and UPDATE (rename, reorder, `active`) but never DELETE:
// a group is hidden with active = false, because later slices snapshot the group path onto sale lines. No change to
// database/scripts/harden_append_only_privileges.sql is needed for that (DELETE is already revoked store-wide and menu_groups is
// not in the allow-list); the script documents it and tindaflow:harden-database reads it back.
//
// Depth is capped at two levels by MenuGroupService (the parent must itself be a top group); the DB guarantees the parent is a
// different row and that a name is unique among its siblings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['store_id', 'parent_id', 'sort_order']);
        });

        // Added once the primary key exists: a self-referencing foreign key cannot be declared inside CREATE TABLE here.
        Schema::table('menu_groups', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('menu_groups')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE menu_groups ADD CONSTRAINT menu_groups_parent_not_self_check CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement("ALTER TABLE menu_groups ADD CONSTRAINT menu_groups_name_not_blank_check CHECK (btrim(name) <> '')");
        // A name is unique among its siblings (same store, same parent; every top group shares the NULL parent), ignoring case.
        DB::statement("CREATE UNIQUE INDEX menu_groups_sibling_name_unique ON menu_groups (store_id, COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name))");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_groups');
    }
};

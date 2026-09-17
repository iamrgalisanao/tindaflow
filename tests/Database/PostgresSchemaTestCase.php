<?php

namespace Tests\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// This schema is PostgreSQL-specific (partial unique indexes, JSONB,
// TIMESTAMPTZ, raw CHECK constraints with regex operators) -- it cannot
// run against phpunit.xml's default sqlite in-memory connection, which
// has no equivalent for several of these features. Every test in
// tests/Database/ extends this base class instead, which reconfigures
// the `pgsql` connection at runtime to point at a real local PostgreSQL
// instance and migrates it fresh once per test class.
//
// Run this suite explicitly:
//   php artisan test --testsuite=Database
// Requires a local PostgreSQL 17 server reachable at the settings below
// (matching the one used for Stage 5's live validation -- see
// docs/04-database/schema-validation.md) with a `tindaflow_schema_test`
// database that this suite is free to drop and recreate.
abstract class PostgresSchemaTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('PGSQL_TEST_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('PGSQL_TEST_PORT', '5432'),
            'database.connections.pgsql.database' => env('PGSQL_TEST_DATABASE', 'tindaflow_schema_test'),
            'database.connections.pgsql.username' => env('PGSQL_TEST_USERNAME', 'postgres'),
            'database.connections.pgsql.password' => env('PGSQL_TEST_PASSWORD', ''),
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        // Every test runs inside a transaction that is rolled back
        // afterward, so tests never see each other's fixture rows and
        // the migrated schema only needs to be built once per run.
        DB::rollBack();

        parent::tearDown();
    }
}

<?php

namespace Tests\Database;

use App\Models\User;
use App\Services\StoreSetup\FiscalConfigurationLock;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\RunsRequestsAsApplicationRole;
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

        config(PostgresTestConnection::settings(env('PGSQL_TEST_DATABASE', 'tindaflow_schema_test')));

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        DB::beginTransaction();
        // This base class holds one transaction around the whole test; the override-code guard (CreditOverrideService) must tolerate exactly that.
        config(['tindaflow.credit_override.base_transaction_level' => 1]);

        RunsRequestsAsApplicationRole::$refusals = [];
        if ($this->requestsRunAsTheApplicationRole()) {
            $this->handleRequestsAsTheApplicationRole();
        }
    }

    /**
     * With PGSQL_TEST_AS_APP_ROLE=1 every HTTP request of this suite is handled as the restricted role the deployed application
     * connects as, so a screen or API that needs a privilege the hardening takes away fails here. Fixtures and assertions stay
     * the owner's: tests build, and some deliberately damage, rows the application itself may not change. Work a test does by
     * calling a service or a command directly is not covered, and neither are the classes that run separate processes.
     */
    protected function requestsRunAsTheApplicationRole(): bool
    {
        return filter_var(env('PGSQL_TEST_AS_APP_ROLE', false), FILTER_VALIDATE_BOOL);
    }

    private function handleRequestsAsTheApplicationRole(): void
    {
        // The same provisioning the deployment runs as the table owner, undone with the test's own transaction.
        $exit = Artisan::call('tindaflow:harden-database', ['--password' => 'test-only-password']);
        $this->assertSame(0, $exit, Artisan::output());

        $this->app->make(Kernel::class)->prependMiddleware(RunsRequestsAsApplicationRole::class);
    }

    /** An ADMIN in a store whose fiscal configuration is unlocked (ADR-014), as during installation. */
    protected function fiscalAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        app(FiscalConfigurationLock::class)->unlock($admin->store_id, 60);

        return $admin;
    }

    protected function tearDown(): void
    {
        // Every test runs inside a transaction that is rolled back
        // afterward, so tests never see each other's fixture rows and
        // the migrated schema only needs to be built once per run. Every level is rolled back: a test that failed inside a
        // transaction of its own would otherwise leave the outer one open, holding locks the next test waits on for ever.
        DB::rollBack(0);

        $refused = RunsRequestsAsApplicationRole::$refusals;
        RunsRequestsAsApplicationRole::$refusals = [];
        parent::tearDown();

        $this->assertSame([], $refused, 'the application role was refused a privilege during a request');
    }
}

<?php

namespace Tests\Database;

use Illuminate\Support\Facades\DB;

/**
 * `tindaflow:harden-database`: run as the table owner after every migration by the deployment's migrate step. Like
 * AppendOnlyPrivilegesTest, everything happens in the per-test transaction that is rolled back, so no role is left
 * behind in the cluster.
 */
class HardenDatabaseCommandTest extends PostgresSchemaTestCase
{
    private const APP_ROLE = 'tindaflow_app';

    protected function setUp(): void
    {
        parent::setUp();

        $allowed = DB::selectOne('select rolcreaterole or rolsuper as allowed from pg_roles where rolname = current_user')->allowed;
        if (! $allowed) {
            $this->markTestSkipped('The test database user cannot create roles.');
        }
    }

    public function test_it_creates_a_login_role_that_is_not_a_superuser(): void
    {
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();

        $role = DB::selectOne('select rolcanlogin, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = ?', [self::APP_ROLE]);
        $this->assertTrue($role->rolcanlogin);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolcreaterole);
    }

    public function test_it_is_idempotent_and_keeps_enforcing_after_a_second_run(): void
    {
        $this->artisan('tindaflow:harden-database', ['--password' => 'first'])->assertSuccessful();
        $this->artisan('tindaflow:harden-database', ['--password' => 'second'])->assertSuccessful();

        $this->assertFalse(DB::selectOne("select has_table_privilege('tindaflow_app', 'audit_events', 'UPDATE') as allowed")->allowed);
        $this->assertTrue(DB::selectOne("select has_column_privilege('tindaflow_app', 'sales', 'status', 'UPDATE') as allowed")->allowed);
    }

    public function test_a_table_added_by_a_later_migration_is_covered_on_the_next_run(): void
    {
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();

        DB::unprepared('CREATE TABLE zz_added_later (id uuid primary key)');
        $this->artisan('tindaflow:harden-database')->assertSuccessful();

        $this->assertTrue(DB::selectOne("select has_table_privilege('tindaflow_app', 'zz_added_later', 'INSERT') as allowed")->allowed);
        $this->assertTrue(DB::selectOne("select has_table_privilege('tindaflow_app', 'zz_added_later', 'UPDATE') as allowed")->allowed);
        $this->assertFalse(DB::selectOne("select has_table_privilege('tindaflow_app', 'zz_added_later', 'DELETE') as allowed")->allowed);
    }

    public function test_it_cannot_create_the_role_without_a_password(): void
    {
        config(['tindaflow.database.app_password' => null]);

        $this->artisan('tindaflow:harden-database')
            ->expectsOutputToContain('DB_APP_PASSWORD')
            ->assertFailed();

        $this->assertNull(DB::selectOne('select 1 as present from pg_roles where rolname = ?', [self::APP_ROLE]));
    }

    public function test_it_refuses_to_run_as_the_application_role_itself(): void
    {
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();
        DB::unprepared('SET LOCAL ROLE '.self::APP_ROLE);

        $this->artisan('tindaflow:harden-database')
            ->expectsOutputToContain('application role itself')
            ->assertFailed();
    }

    public function test_the_password_comes_from_configuration_when_no_option_is_given(): void
    {
        config(['tindaflow.database.app_password' => 'from-config']);

        $this->artisan('tindaflow:harden-database')->assertSuccessful();

        $this->assertNotNull(DB::selectOne('select 1 as present from pg_roles where rolname = ?', [self::APP_ROLE]));
    }
}

<?php

namespace Tests\Database;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Database\Concerns\RunsRequestsAsApplicationRole;

/**
 * Proves the PGSQL_TEST_AS_APP_ROLE mode of this suite does what it says, whether or not the mode is on: a request really is
 * handled as the restricted role, the test gets its own role back, and a refused privilege cannot pass unnoticed.
 */
class ApplicationRoleRequestsTest extends PostgresSchemaTestCase
{
    protected function requestsRunAsTheApplicationRole(): bool
    {
        return false; // each test sets up the mode itself, so that it is tested the same way in both modes
    }

    private function provisionAndRoute(): string
    {
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();
        $this->app->make(Kernel::class)->prependMiddleware(RunsRequestsAsApplicationRole::class);
        Route::get('/api/_test/who', fn () => ['user' => DB::selectOne('select current_user as name')->name]);
        Route::get('/api/_test/forbidden', fn () => DB::table('audit_events')->delete());

        return DB::selectOne('select current_user as name')->name;
    }

    public function test_a_request_is_handled_as_the_application_role_and_the_test_gets_its_own_role_back(): void
    {
        $owner = $this->provisionAndRoute();
        $this->assertNotSame('tindaflow_app', $owner);

        $this->getJson('/api/_test/who')->assertOk()->assertExactJson(['user' => 'tindaflow_app']);

        $this->assertSame($owner, DB::selectOne('select current_user as name')->name);

        // A test that had already taken the restricted role keeps it.
        DB::unprepared('SET LOCAL ROLE tindaflow_app');
        $this->getJson('/api/_test/who')->assertOk();
        $this->assertSame('tindaflow_app', DB::selectOne('select current_user as name')->name);
        DB::unprepared('RESET ROLE');
    }

    public function test_a_privilege_the_application_role_lacks_is_remembered_even_though_the_request_only_failed(): void
    {
        $this->provisionAndRoute();

        // A savepoint, as every write path of the application has one: the refused statement must not poison the test's transaction.
        DB::beginTransaction();
        $this->getJson('/api/_test/forbidden')->assertStatus(500);
        DB::rollBack();

        $this->assertCount(1, RunsRequestsAsApplicationRole::$refusals);
        $this->assertStringContainsString('permission denied for table audit_events', RunsRequestsAsApplicationRole::$refusals[0]);
        RunsRequestsAsApplicationRole::$refusals = []; // expected here, so tearDown must not report it
    }
}

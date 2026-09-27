<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the role the running application connects as and applies database/scripts/harden_append_only_privileges.sql,
 * so the append-only rules of invariants.md #2, #23, #41, #45 and #48 are enforced by PostgreSQL and not merely by the
 * application happening not to break them.
 *
 * Run it as the role that owns the tables (the one that runs the migrations), never as the application role: the
 * command creates or updates `tindaflow_app`, gives it the ordinary privileges, then narrows them with the script.
 * It is idempotent and is run after every migration, so a table added later is covered too. docker/compose.yaml runs
 * it in the one-shot `migrate` service; the long-running `app` service then connects as `tindaflow_app` and never holds
 * the owner's credentials.
 *
 * With one shared role (local development, or a deployment that has not adopted two roles) there is nothing to
 * enforce, so the command says so and changes nothing.
 */
class HardenDatabase extends Command
{
    public const APP_ROLE = 'tindaflow_app';

    /**
     * The only tables the running application may DELETE from (kept in step with the GRANT in the SQL script): the
     * framework's database session and cache stores, and two catalogue/count child tables. No money or audit data.
     */
    public const DELETABLE_TABLES = ['cache', 'cache_locks', 'product_barcodes', 'sessions', 'stock_count_lines'];

    protected $signature = 'tindaflow:harden-database
        {--password= : The application role\'s password (defaults to DB_APP_PASSWORD)}';

    protected $description = 'Create the application database role and enforce the append-only privileges (run as the table owner)';

    public function handle(): int
    {
        $connection = DB::connection();
        $current = (string) $connection->selectOne('select current_user as name')->name;

        if ($current === self::APP_ROLE) {
            $this->error('This is connected as '.self::APP_ROLE.', the application role itself. Run it as the role that owns the tables.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? config('tindaflow.database.app_password') ?? '');
        $exists = $connection->selectOne('select 1 as present from pg_roles where rolname = ?', [self::APP_ROLE]) !== null;

        if (! $exists && $password === '') {
            $this->error('The '.self::APP_ROLE.' role does not exist yet. Set DB_APP_PASSWORD (or pass --password) so it can be created with a login.');

            return self::FAILURE;
        }

        $connection->transaction(function () use ($connection, $exists, $password) {
            $role = self::APP_ROLE;
            $quotedPassword = $connection->getPdo()->quote($password);

            if (! $exists) {
                $connection->unprepared("CREATE ROLE {$role} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD {$quotedPassword}");
            } elseif ($password !== '') {
                $connection->unprepared("ALTER ROLE {$role} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD {$quotedPassword}");
            }

            // What the application role is provisioned with, before the script narrows it.
            $connection->unprepared("REVOKE CREATE ON SCHEMA public FROM {$role}");
            $connection->unprepared("GRANT USAGE ON SCHEMA public TO {$role}");
            $connection->unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
            $connection->unprepared("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");

            $connection->unprepared((string) file_get_contents(base_path('database/scripts/harden_append_only_privileges.sql')));

            $this->assertEnforced();
        });

        $this->info('The '.self::APP_ROLE.' role is provisioned and the append-only privileges are enforced.');

        return self::SUCCESS;
    }

    /** Reads back what the database now enforces; an exception here rolls the whole provisioning back. */
    private function assertEnforced(): void
    {
        $role = self::APP_ROLE;
        $checks = [
            'the role must not be a superuser (a superuser ignores every privilege)' => "select not rolsuper from pg_roles where rolname = '{$role}'",
            'audit_events must refuse UPDATE' => "select not has_table_privilege('{$role}', 'audit_events', 'UPDATE')",
            'audit_events must refuse DELETE' => "select not has_table_privilege('{$role}', 'audit_events', 'DELETE')",
            'the electronic journal must refuse UPDATE' => "select not has_table_privilege('{$role}', 'electronic_journal_entries', 'UPDATE')",
            'stock_movements must refuse UPDATE' => "select not has_table_privilege('{$role}', 'stock_movements', 'UPDATE')",
            'sales must refuse UPDATE of the total' => "select not has_column_privilege('{$role}', 'sales', 'grand_total', 'UPDATE')",
            'sales must allow UPDATE of the status (void and refund)' => "select has_column_privilege('{$role}', 'sales', 'status', 'UPDATE')",
        ];

        // Deletable tables must be EXACTLY the allow-list: no financial or audit table, and none the app needs missing.
        $deletable = DB::table('information_schema.role_table_grants')
            ->where('grantee', $role)->where('privilege_type', 'DELETE')->where('table_schema', 'public')
            ->orderBy('table_name')->pluck('table_name')->all();
        if ($deletable !== self::DELETABLE_TABLES) {
            throw new \RuntimeException('Database hardening did not take effect: the role may delete from ['.implode(', ', $deletable).'] but must be able to delete from exactly ['.implode(', ', self::DELETABLE_TABLES).'].');
        }

        foreach ($checks as $requirement => $sql) {
            $row = (array) DB::selectOne($sql);
            if (! (bool) array_values($row)[0]) {
                throw new \RuntimeException("Database hardening did not take effect: {$requirement}.");
            }
        }
    }
}

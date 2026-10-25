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
            'menu_groups must allow UPDATE (rename, reorder, hide)' => "select has_table_privilege('{$role}', 'menu_groups', 'UPDATE')",
            'menu_groups must refuse DELETE (a group is hidden, never deleted)' => "select not has_table_privilege('{$role}', 'menu_groups', 'DELETE')",
            'menu_prices must refuse UPDATE (a price change is a new row)' => "select not has_table_privilege('{$role}', 'menu_prices', 'UPDATE')",
            'menu_prices must refuse DELETE' => "select not has_table_privilege('{$role}', 'menu_prices', 'DELETE')",
            'menu_prices must allow INSERT (a price change is a new row)' => "select has_table_privilege('{$role}', 'menu_prices', 'INSERT')",
            'sale_item_selections must refuse UPDATE (what was chosen on a sold meal never changes)' => "select not has_table_privilege('{$role}', 'sale_item_selections', 'UPDATE')",
            'sale_item_selections must refuse DELETE' => "select not has_table_privilege('{$role}', 'sale_item_selections', 'DELETE')",
            'sale_item_selections must allow INSERT (written in the sale transaction)' => "select has_table_privilege('{$role}', 'sale_item_selections', 'INSERT')",
            'sale_items must refuse UPDATE of the meal snapshot columns' => "select bool_and(not has_column_privilege('{$role}', 'sale_items', c, 'UPDATE')) from unnest(array['menu_group_path_snapshot','meal_id','meal_name_snapshot','parent_line_number']) as c",
            'the meal menu configuration tables must allow UPDATE (rename, reorder, hide) but refuse DELETE (hide, never delete)' => "select bool_and(has_table_privilege('{$role}', t, 'UPDATE') and not has_table_privilege('{$role}', t, 'DELETE')) from unnest(array['dish_categories','dishes','meals','meal_option_groups','meal_options']) as t",
            'sale_slips must refuse UPDATE (a slip never changes)' => "select not has_table_privilege('{$role}', 'sale_slips', 'UPDATE')",
            'sale_slips must refuse DELETE' => "select not has_table_privilege('{$role}', 'sale_slips', 'DELETE')",
            'sale_slips must allow INSERT (written in the sale transaction)' => "select has_table_privilege('{$role}', 'sale_slips', 'INSERT')",
            'slip_counters must allow UPDATE of the running number only (last_number, updated_at)' => "select has_column_privilege('{$role}', 'slip_counters', 'last_number', 'UPDATE') and has_column_privilege('{$role}', 'slip_counters', 'updated_at', 'UPDATE') and not has_column_privilege('{$role}', 'slip_counters', 'terminal_id', 'UPDATE')",
            'slip_counters must allow INSERT (a terminal\'s first slip) but refuse DELETE' => "select has_table_privilege('{$role}', 'slip_counters', 'INSERT') and not has_table_privilege('{$role}', 'slip_counters', 'DELETE')",
            'sales must refuse UPDATE of the document mode (written once at the sale)' => "select not has_column_privilege('{$role}', 'sales', 'document_mode', 'UPDATE')",
            'store_settings must allow UPDATE (the document mode and the slip footer)' => "select has_table_privilege('{$role}', 'store_settings', 'UPDATE')",
            'employees must refuse UPDATE of the code, the type and the store (the code is the QR value and is never changed)' => "select bool_and(not has_column_privilege('{$role}', 'employees', c, 'UPDATE')) from unnest(array['employee_code','employee_type','store_id','created_by']) as c",
            'employees must allow UPDATE of the names and the active flag' => "select bool_and(has_column_privilege('{$role}', 'employees', c, 'UPDATE')) from unnest(array['first_name','middle_name','last_name','billing_party_id','active']) as c",
            'employees must allow INSERT but refuse DELETE (deactivate, never delete)' => "select has_table_privilege('{$role}', 'employees', 'INSERT') and not has_table_privilege('{$role}', 'employees', 'DELETE')",
            'employee_code_sequences must allow UPDATE of last_number only and refuse DELETE' => "select has_column_privilege('{$role}', 'employee_code_sequences', 'last_number', 'UPDATE') and not has_column_privilege('{$role}', 'employee_code_sequences', 'store_id', 'UPDATE') and has_table_privilege('{$role}', 'employee_code_sequences', 'INSERT') and not has_table_privilege('{$role}', 'employee_code_sequences', 'DELETE')",
            'credit_policies must allow UPDATE of the limit only and refuse DELETE' => "select has_column_privilege('{$role}', 'credit_policies', 'limit_per_cutoff', 'UPDATE') and not has_column_privilege('{$role}', 'credit_policies', 'employee_type', 'UPDATE') and not has_column_privilege('{$role}', 'credit_policies', 'store_id', 'UPDATE') and has_table_privilege('{$role}', 'credit_policies', 'INSERT') and not has_table_privilege('{$role}', 'credit_policies', 'DELETE')",
            'employee_credit_entries must refuse UPDATE (the credit ledger is append-only; the balance is derived)' => "select not has_table_privilege('{$role}', 'employee_credit_entries', 'UPDATE')",
            'employee_credit_entries must refuse DELETE' => "select not has_table_privilege('{$role}', 'employee_credit_entries', 'DELETE')",
            'employee_credit_entries must allow INSERT (written in the sale, void and refund transactions)' => "select has_table_privilege('{$role}', 'employee_credit_entries', 'INSERT')",
            'sales must refuse UPDATE of the employee (written once at the sale)' => "select not has_column_privilege('{$role}', 'sales', 'employee_id', 'UPDATE')",
            'billing_parties must allow UPDATE but refuse DELETE' => "select has_table_privilege('{$role}', 'billing_parties', 'UPDATE') and not has_table_privilege('{$role}', 'billing_parties', 'DELETE')",
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

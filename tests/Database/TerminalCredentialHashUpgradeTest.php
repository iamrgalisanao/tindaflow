<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Module A Decision Register (module-a-auth-terminal-initialization.md
 * SS14, Ruling 3) / A0 step 5 -- exercises the REAL
 * 2026_09_17_010000_add_credential_hash_unique_index_to_terminals_table
 * migration's up()/down() directly, the same technique
 * InvoiceSeriesCounterUpgradeTest uses: PostgresSchemaTestCase already
 * migrates the full amended schema once per class; each test that needs
 * the pre-amendment shape calls this migration's own down() first, then
 * up() again to exercise the real (re)upgrade path. Both calls run
 * inside this test's own transaction (rolled back in tearDown).
 */
class TerminalCredentialHashUpgradeTest extends PostgresSchemaTestCase
{
    private string $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Credential Hash Test Store', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function credentialHashMigration(): object
    {
        return require base_path('database/migrations/2026_09_17_010000_add_credential_hash_unique_index_to_terminals_table.php');
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertTerminal(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('terminals')->insert(array_merge([
            'id' => $id,
            'store_id' => $this->storeId,
            'terminal_code' => 'T-'.Str::random(6),
            'status' => 'ACTIVE',
            'credential_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_multiple_null_credential_hashes_are_accepted(): void
    {
        $this->insertTerminal(['credential_hash' => null]);
        $this->insertTerminal(['credential_hash' => null]);
        $this->insertTerminal(['credential_hash' => null]);

        $this->assertSame(3, DB::table('terminals')->whereNull('credential_hash')->count());
    }

    public function test_a_single_non_null_credential_hash_is_accepted(): void
    {
        $this->insertTerminal(['credential_hash' => hash('sha256', 'terminal-a-secret')]);

        $this->assertSame(1, DB::table('terminals')->whereNotNull('credential_hash')->count());
    }

    public function test_duplicate_non_null_credential_hash_is_rejected(): void
    {
        $hash = hash('sha256', 'shared-secret');
        $this->insertTerminal(['credential_hash' => $hash]);

        $this->expectException(QueryException::class);

        $this->insertTerminal(['credential_hash' => $hash]);
    }

    public function test_different_non_null_credential_hashes_are_accepted(): void
    {
        $this->insertTerminal(['credential_hash' => hash('sha256', 'terminal-a-secret')]);
        $this->insertTerminal(['credential_hash' => hash('sha256', 'terminal-b-secret')]);

        $this->assertSame(2, DB::table('terminals')->whereNotNull('credential_hash')->count());
    }

    public function test_migration_down_removes_the_unique_index(): void
    {
        $this->credentialHashMigration()->down();

        // With the index removed, a duplicate non-null hash must no
        // longer be rejected at the database level.
        $hash = hash('sha256', 'shared-secret');
        $this->insertTerminal(['credential_hash' => $hash]);
        $this->insertTerminal(['credential_hash' => $hash]);

        $this->assertSame(2, DB::table('terminals')->where('credential_hash', $hash)->count());
    }

    public function test_migration_up_restores_the_unique_index_after_down(): void
    {
        $this->credentialHashMigration()->down();
        $this->insertTerminal(['credential_hash' => hash('sha256', 'terminal-a-secret')]);

        $this->credentialHashMigration()->up();

        $this->expectException(QueryException::class);

        $this->insertTerminal(['credential_hash' => hash('sha256', 'terminal-a-secret')]);
    }

    public function test_existing_valid_legacy_rows_survive_the_upgrade(): void
    {
        $this->credentialHashMigration()->down();

        $unenrolledId = $this->insertTerminal(['credential_hash' => null]);
        $enrolledAId = $this->insertTerminal(['credential_hash' => hash('sha256', 'legacy-a')]);
        $enrolledBId = $this->insertTerminal(['credential_hash' => hash('sha256', 'legacy-b')]);

        $this->credentialHashMigration()->up();

        $this->assertNull(DB::table('terminals')->where('id', $unenrolledId)->value('credential_hash'));
        $this->assertSame(hash('sha256', 'legacy-a'), DB::table('terminals')->where('id', $enrolledAId)->value('credential_hash'));
        $this->assertSame(hash('sha256', 'legacy-b'), DB::table('terminals')->where('id', $enrolledBId)->value('credential_hash'));
        $this->assertSame(3, DB::table('terminals')->count(), 'the upgrade must not add, drop, or merge any row');
    }

    public function test_migration_aborts_without_mutating_data_when_legacy_duplicates_exist(): void
    {
        $this->credentialHashMigration()->down();

        $sharedHash = hash('sha256', 'colliding-legacy-secret');
        $firstId = $this->insertTerminal(['credential_hash' => $sharedHash]);
        $secondId = $this->insertTerminal(['credential_hash' => $sharedHash]);

        try {
            $this->credentialHashMigration()->up();
            $this->fail('Expected the migration to abort on duplicate legacy credential_hash values.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('credential_hash', $e->getMessage());
        }

        // Neither row was mutated, nulled, or dropped to force the upgrade through.
        $this->assertSame($sharedHash, DB::table('terminals')->where('id', $firstId)->value('credential_hash'));
        $this->assertSame($sharedHash, DB::table('terminals')->where('id', $secondId)->value('credential_hash'));
    }
}

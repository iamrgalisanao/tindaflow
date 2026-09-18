<?php

namespace Tests\Database;

use App\Models\TerminalEnrollmentToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A3 hardening: proves ADR-011's single-use enrollment-token semantics
 * hold under genuine concurrency -- "two independent contenders using
 * the same valid token, at most one successful claim" -- using two
 * INDEPENDENT OS processes, each with its own PostgreSQL connection,
 * synchronized at a file-based barrier, mirroring
 * IdempotencyConcurrencyTest's already-established pattern exactly
 * (same reasons for not extending PostgresSchemaTestCase: fixtures must
 * be visible to separate worker processes, which an uncommitted
 * transaction rolled back in tearDown would hide).
 */
class TerminalEnrollmentConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => self::DATABASE,
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => '',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        DB::table('terminal_enrollment_tokens')->truncate();
        DB::table('terminals')->truncate();
        DB::table('stores')->truncate();
        DB::table('users')->truncate();

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_enrollment_race_'.Str::random(8);
        mkdir($this->scratchDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    public function test_two_concurrent_enrollment_attempts_with_the_same_token_never_both_succeed(): void
    {
        $storeId = (string) Str::uuid();
        $terminalId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('stores')->insert(['id' => $storeId, 'name' => 'Race Test Store', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert([
            'id' => $userId, 'store_id' => $storeId, 'name' => 'Admin', 'email' => 'admin@race.test',
            'password_hash' => 'x', 'role' => 'ADMIN', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('terminals')->insert([
            'id' => $terminalId, 'store_id' => $storeId, 'terminal_code' => 'RACE-01',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plaintext = Str::random(64);
        DB::table('terminal_enrollment_tokens')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_id' => $terminalId,
            'token_hash' => hash('sha256', $plaintext), 'created_by' => $userId,
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);

        [$resultA, $resultB] = $this->race($plaintext);

        // Exactly one worker succeeds; the other sees the token as invalid.
        $outcomes = [$resultA['outcome'], $resultB['outcome']];
        sort($outcomes);
        $this->assertSame(['exception', 'success'], $outcomes, json_encode([$resultA, $resultB]));

        $loser = $resultA['outcome'] === 'exception' ? $resultA : $resultB;
        $this->assertSame('App\\Domain\\Exceptions\\EnrollmentTokenInvalidException', $loser['class']);

        // The token itself was consumed exactly once.
        $token = TerminalEnrollmentToken::where('terminal_id', $terminalId)->first();
        $this->assertNotNull($token->used_at);

        // The terminal ends up with exactly one credential -- never two
        // simultaneously-valid credentials from the same token.
        $terminal = DB::table('terminals')->where('id', $terminalId)->first();
        $this->assertNotNull($terminal->credential_hash);

        $winner = $resultA['outcome'] === 'success' ? $resultA : $resultB;
        $this->assertSame(hash('sha256', $winner['credential']), $terminal->credential_hash);
    }

    /** @return array{0: array, 1: array} decoded JSON output from worker A and worker B */
    private function race(string $plaintext): array
    {
        $workerScript = base_path('tests/Database/support/terminal_enrollment_race_worker.php');
        $readyA = $this->scratchDir.'/ready_a';
        $readyB = $this->scratchDir.'/ready_b';
        $go = $this->scratchDir.'/go';
        $outA = $this->scratchDir.'/out_a.json';
        $outB = $this->scratchDir.'/out_b.json';

        $processA = Process::start([PHP_BINARY, $workerScript, $plaintext, $readyA, $go, $outA, self::DATABASE]);
        $processB = Process::start([PHP_BINARY, $workerScript, $plaintext, $readyB, $go, $outB, self::DATABASE]);

        $deadline = microtime(true) + 10;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }

        file_put_contents($go, '1');

        $resultA = $processA->wait();
        $resultB = $processB->wait();

        $this->assertTrue($resultA->successful(), "worker A process failed: {$resultA->errorOutput()}");
        $this->assertTrue($resultB->successful(), "worker B process failed: {$resultB->errorOutput()}");

        $this->assertFileExists($outA, 'worker A did not write an output file');
        $this->assertFileExists($outB, 'worker B did not write an output file');

        return [
            json_decode(file_get_contents($outA), true),
            json_decode(file_get_contents($outB), true),
        ];
    }
}

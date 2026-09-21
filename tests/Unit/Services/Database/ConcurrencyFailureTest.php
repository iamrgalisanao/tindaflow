<?php

namespace Tests\Unit\Services\Database;

use App\Services\Database\ConcurrencyFailure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 27: which database errors are "you lost a race, send it again" and which are real faults. The SQLSTATE decides,
 * not the message, so a server that speaks another language is still recognised.
 */
class ConcurrencyFailureTest extends TestCase
{
    private function failure(string $sqlState, string $message = 'anything'): QueryException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$sqlState, 7, $message];

        return new QueryException('pgsql', 'update stock_balances set quantity_on_hand = 1', [], $pdo);
    }

    public function test_a_deadlock_and_a_serialization_failure_are_recognised_by_their_sqlstate(): void
    {
        $this->assertTrue(ConcurrencyFailure::caused($this->failure('40P01')));
        $this->assertTrue(ConcurrencyFailure::caused($this->failure('40001')));
    }

    public function test_the_language_of_the_message_does_not_matter(): void
    {
        $this->assertTrue(ConcurrencyFailure::caused($this->failure('40P01', 'deadlock erkannt')));
        $this->assertTrue(ConcurrencyFailure::caused($this->failure('40P01', 'se ha detectado un deadlock')));
    }

    public function test_the_english_message_is_still_recognised_when_no_sqlstate_is_available(): void
    {
        $bare = new QueryException('pgsql', 'update x', [], new PDOException('SQLSTATE[40P01]: Deadlock found: 7 ERROR: deadlock detected'));

        $this->assertTrue(ConcurrencyFailure::caused($bare));
    }

    public function test_the_deadlock_laravel_raises_inside_a_nested_transaction_is_recognised(): void
    {
        $this->assertTrue(ConcurrencyFailure::caused(new DeadlockException('deadlock')));
    }

    public function test_real_faults_are_not_mistaken_for_a_lost_race(): void
    {
        foreach (['23505' => 'unique', '23503' => 'foreign key', '23514' => 'check', '42P01' => 'undefined table', '57014' => 'query cancelled'] as $state => $why) {
            $this->assertFalse(ConcurrencyFailure::caused($this->failure($state, "a {$why} error")), $why);
        }
        $this->assertFalse(ConcurrencyFailure::caused(new RuntimeException('deadlock detected')), 'only database exceptions count');
    }

    public function test_the_sqlstate_can_be_read_back_for_the_log(): void
    {
        $this->assertSame('40P01', ConcurrencyFailure::sqlState($this->failure('40P01')));
        $this->assertNull(ConcurrencyFailure::sqlState(new RuntimeException('x')));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/**
 * Stage 27: a request that PostgreSQL aborted for losing a race with another request answers with the existing,
 * retryable 409 CONCURRENCY_CONFLICT instead of a 500, and nothing from the driver reaches the client. Every other
 * database error is still a 500. The routes exist only for this test.
 */
class DeadlockResponseTest extends TestCase
{
    private function failWith(string $sqlState): void
    {
        Route::get('/api/v1/_test/db-failure', function () use ($sqlState) {
            $pdo = new PDOException('SQLSTATE['.$sqlState.']: secret detail from the driver');
            $pdo->errorInfo = [$sqlState, 7, 'secret detail from the driver'];

            throw new QueryException('pgsql', 'update stock_balances set quantity_on_hand = 9 where product_id = ?', ['SECRET-BINDING'], $pdo);
        });
    }

    public function test_a_deadlock_is_a_retryable_409_in_the_standard_envelope(): void
    {
        $this->failWith('40P01');

        $response = $this->getJson('/api/v1/_test/db-failure');

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'CONCURRENCY_CONFLICT');
        $response->assertJsonPath('error.details.retryable', true);
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
        $this->assertNotEmpty($response->json('error.request_id'));
        $this->assertSame('1', $response->headers->get('Retry-After'));
    }

    public function test_a_serialization_failure_is_treated_the_same_way(): void
    {
        $this->failWith('40001');

        $this->getJson('/api/v1/_test/db-failure')->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENCY_CONFLICT');
    }

    public function test_nothing_from_the_driver_reaches_the_client(): void
    {
        $this->failWith('40P01');

        $body = $this->getJson('/api/v1/_test/db-failure')->getContent();

        foreach (['SECRET-BINDING', 'secret detail', 'stock_balances', 'SQLSTATE', '40P01', 'quantity_on_hand'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_any_other_database_error_is_still_a_server_error(): void
    {
        $this->failWith('42P01');

        $response = $this->getJson('/api/v1/_test/db-failure');

        $response->assertStatus(500);
        $this->assertNotSame('CONCURRENCY_CONFLICT', $response->json('error.code'));
    }
}

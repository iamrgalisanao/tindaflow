<?php

namespace Tests\Database;

use App\Domain\Exceptions\ConcurrencyConflictException;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\ShiftNotOpenException;
use App\Services\Idempotency\DatabaseExceptionTranslator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage 6A: proves DatabaseExceptionTranslator against a REAL PostgreSQL
 * constraint violation, not a hand-built fake QueryException whose shape
 * might not match what Laravel/PDO actually produce.
 */
class DatabaseExceptionTranslatorTest extends PostgresSchemaTestCase
{
    private DatabaseExceptionTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new DatabaseExceptionTranslator;
    }

    public function test_unique_violation_translates_to_concurrency_conflict_and_never_leaks_raw_detail(): void
    {
        $storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('terminals')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_code' => 'DUP-01',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('terminals')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_code' => 'DUP-01', // same code, same store
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('expected a unique constraint violation');
        } catch (QueryException $e) {
            try {
                $this->translator->translate($e);
                $this->fail('expected translate() to throw');
            } catch (DomainException $domainException) {
                $this->assertInstanceOf(ConcurrencyConflictException::class, $domainException);
                $this->assertSame('CONCURRENCY_CONFLICT', $domainException->errorCode());
                $this->assertSame(409, $domainException->httpStatus());

                $envelope = $domainException->toErrorEnvelope();
                $this->assertStringNotContainsStringIgnoringCase('terminals_store_id_terminal_code_unique', json_encode($envelope));
                $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', json_encode($envelope));
            }
        }
    }

    public function test_registered_constraint_maps_to_a_specific_exception(): void
    {
        $this->translator->registerConstraint(
            'terminals_store_id_terminal_code_unique',
            fn () => ShiftNotOpenException::forShift('placeholder') // any DomainException works for this test
        );

        $storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('terminals')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_code' => 'DUP-02',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('terminals')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_code' => 'DUP-02',
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('expected a unique constraint violation');
        } catch (QueryException $e) {
            $this->expectException(ShiftNotOpenException::class);
            $this->translator->translate($e);
        }
    }

    public function test_an_unrelated_exception_is_rethrown_unmodified(): void
    {
        $original = new RuntimeException('not a database exception at all');

        $this->expectExceptionMessage('not a database exception at all');
        $this->translator->translate($original);
    }
}

<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

// Owner hardening pass, 2026-09-16. Ground-truth verification that
// PostgreSQL NUMERIC(p,s) ROUNDS excess fractional input to the
// declared scale rather than rejecting it -- the opposite of what an
// earlier draft of schema-validation.md/stage-5-report.md implied.
// PostgreSQL 17 docs: "If the scale of a value to be stored is greater
// than the declared scale of the column, the system will round the
// value." This class exists specifically so that claim is never made
// again without a test enforcing it. Every INSERT here is raw SQL via
// DB::table(), with no Laravel validation, Eloquent cast, or DTO in the
// path -- exactly what the owner asked to be bypassed.
class PrecisionCoercionTest extends PostgresSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE IF NOT EXISTS precision_probe (money_12_2 NUMERIC(12,2), qty_10_3 NUMERIC(10,3))');
    }

    protected function tearDown(): void
    {
        // Some tests deliberately trigger a QueryException inside a
        // nested transaction (savepoint) via attemptFails(); the
        // savepoint absorbs the abort, so the outer transaction set up
        // by PostgresSchemaTestCase::setUp() is always still usable
        // here. DROP TABLE runs before that outer rollback.
        DB::statement('DROP TABLE IF EXISTS precision_probe');
        parent::tearDown();
    }

    /** Runs $callback in a nested transaction (SAVEPOINT) and returns whether it threw. */
    private function attemptFails(callable $callback): bool
    {
        try {
            DB::transaction($callback);

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    #[DataProvider('moneyRoundingCases')]
    public function test_money_scale_overage_is_rounded_not_rejected(float $input, string $expectedStored): void
    {
        $failed = $this->attemptFails(function () use ($input) {
            DB::table('precision_probe')->insert(['money_12_2' => $input]);
        });

        $this->assertFalse($failed, "NUMERIC(12,2) receiving {$input} must not be rejected -- it should be rounded, not raise an error");

        $stored = DB::table('precision_probe')->value('money_12_2');
        $this->assertSame($expectedStored, $stored, "NUMERIC(12,2) receiving {$input} must round to {$expectedStored}");

        DB::table('precision_probe')->delete();
    }

    public static function moneyRoundingCases(): array
    {
        return [
            '1.234 rounds down to 1.23' => [1.234, '1.23'],
            '12.3456 rounds up to 12.35' => [12.3456, '12.35'],
            '0.001 rounds down to 0.00' => [0.001, '0.00'],
            '0.005 rounds up to 0.01 (half-up at the boundary)' => [0.005, '0.01'],
        ];
    }

    #[DataProvider('quantityRoundingCases')]
    public function test_quantity_scale_overage_is_rounded_not_rejected(float $input, string $expectedStored): void
    {
        $failed = $this->attemptFails(function () use ($input) {
            DB::table('precision_probe')->insert(['qty_10_3' => $input]);
        });

        $this->assertFalse($failed, "NUMERIC(10,3) receiving {$input} must not be rejected -- it should be rounded, not raise an error");

        $stored = DB::table('precision_probe')->value('qty_10_3');
        $this->assertSame($expectedStored, $stored, "NUMERIC(10,3) receiving {$input} must round to {$expectedStored}");

        DB::table('precision_probe')->delete();
    }

    public static function quantityRoundingCases(): array
    {
        return [
            '1.2345 rounds to 1.235' => [1.2345, '1.235'],
        ];
    }

    public function test_money_magnitude_overflow_is_still_rejected_after_rounding(): void
    {
        // 11 integer digits exceeds NUMERIC(12,2)'s 10-digit integer
        // capacity -- this is the genuinely-rejected case, distinct from
        // scale overage above.
        $failed = $this->attemptFails(function () {
            DB::table('precision_probe')->insert(['money_12_2' => 99999999999.99]);
        });

        $this->assertTrue($failed, 'a value whose magnitude exceeds NUMERIC(12,2)\'s integer capacity must still be rejected');
    }

    public function test_quantity_rounding_that_crosses_the_magnitude_boundary_is_rejected(): void
    {
        // 9999999.9995 rounds to 10000000.000 at scale 3, which THEN
        // overflows NUMERIC(10,3)'s 7-integer-digit limit -- rounding
        // happens first, and the overflow check applies to the rounded
        // result, not the original input.
        $failed = $this->attemptFails(function () {
            DB::table('precision_probe')->insert(['qty_10_3' => 9999999.9995]);
        });

        $this->assertTrue($failed, 'rounding that pushes a value across the magnitude boundary must still be rejected');
    }
}

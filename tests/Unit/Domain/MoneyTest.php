<?php

namespace Tests\Unit\Domain;

use App\Domain\Money;
use App\Domain\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_rejects_malformed_amount_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money('120.5'); // only 1 decimal place
    }

    public function test_rejects_more_than_two_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money('120.505');
    }

    public function test_normalizes_negative_zero(): void
    {
        $money = new Money('-0.00');

        $this->assertSame('0.00', $money->toApiString());
        $this->assertTrue($money->isZero());
    }

    public function test_add(): void
    {
        $result = (new Money('100.00'))->add(new Money('20.50'));

        $this->assertSame('120.50', $result->toApiString());
    }

    public function test_subtract(): void
    {
        $result = (new Money('100.00'))->subtract(new Money('20.50'));

        $this->assertSame('79.50', $result->toApiString());
    }

    public function test_cannot_operate_across_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Money('100.00', 'PHP'))->add(new Money('1.00', 'USD'));
    }

    public function test_equality_and_comparison(): void
    {
        $a = new Money('100.00');
        $b = new Money('100.00');
        $c = new Money('100.01');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertTrue($c->greaterThan($a));
        $this->assertTrue($a->lessThan($c));
        $this->assertSame(0, $a->compareTo($b));
    }

    public function test_is_zero_positive_negative(): void
    {
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue((new Money('1.00'))->isPositive());
        $this->assertTrue((new Money('-1.00'))->isNegative());
    }

    /** @dataProvider multiplicationCases */
    #[DataProvider('multiplicationCases')]
    public function test_multiply_by_quantity_rounds_half_up_at_the_single_materialization_point(
        string $unitPrice,
        string $quantity,
        string $expectedGrossLine
    ): void {
        $result = (new Money($unitPrice))->multiplyByQuantity(new Quantity($quantity));

        $this->assertSame($expectedGrossLine, $result->toApiString());
    }

    public static function multiplicationCases(): array
    {
        return [
            'whole quantity' => ['65.00', '2.000', '130.00'],
            'fractional quantity' => ['250.00', '0.500', '125.00'],
            // 0.01 x 2.500 = 0.025 exactly -> rounds half-up to 0.03, not 0.02 (bcmath truncates, not rounds)
            'rounds half-up not down' => ['0.01', '2.500', '0.03'],
        ];
    }

    public function test_allocate_splits_total_exactly_across_weights(): void
    {
        $result = (new Money('20.00'))->allocate([
            1 => '130.00',
            2 => '250.00',
            3 => '100.00',
        ]);

        // Matches the frozen Stage 4 mixed-tax regression fixture exactly:
        // shares tie at the largest-remainder step; ascending line_number wins.
        $this->assertSame('5.42', $result[1]->toApiString());
        $this->assertSame('10.42', $result[2]->toApiString());
        $this->assertSame('4.16', $result[3]->toApiString());

        $sum = $result[1]->add($result[2])->add($result[3]);
        $this->assertTrue($sum->equals(new Money('20.00')));
    }

    public function test_allocate_ties_broken_by_ascending_key_order_not_arbitrary_order(): void
    {
        // Three equal weights, total not evenly divisible by 3 -- the
        // residual centavo(s) must go to the earliest keys, in the order
        // supplied, never a different order.
        $result = (new Money('1.00'))->allocate([
            10 => '100',
            20 => '100',
            30 => '100',
        ]);

        $this->assertSame('0.34', $result[10]->toApiString());
        $this->assertSame('0.33', $result[20]->toApiString());
        $this->assertSame('0.33', $result[30]->toApiString());
    }

    public function test_allocate_rejects_nonzero_total_with_no_eligible_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Money('10.00'))->allocate([]);
    }

    public function test_allocate_rejects_nonzero_total_with_zero_basis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Money('10.00'))->allocate([1 => '0', 2 => '0']);
    }

    public function test_allocate_zero_total_returns_all_zero(): void
    {
        $result = Money::zero()->allocate([1 => '100', 2 => '200']);

        $this->assertTrue($result[1]->isZero());
        $this->assertTrue($result[2]->isZero());
    }

    public function test_no_floating_point_artifacts_on_repeated_addition(): void
    {
        $money = Money::zero();
        for ($i = 0; $i < 10; $i++) {
            $money = $money->add(new Money('0.10'));
        }

        // A naive float accumulation of 0.1 x 10 famously yields
        // 0.9999999999999999 in IEEE 754 double precision -- this must not.
        $this->assertSame('1.00', $money->toApiString());
    }
}

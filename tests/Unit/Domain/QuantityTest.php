<?php

namespace Tests\Unit\Domain;

use App\Domain\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuantityTest extends TestCase
{
    /** @dataProvider validValues */
    #[DataProvider('validValues')]
    public function test_accepts_and_normalizes_valid_values(string $input, string $expected): void
    {
        $this->assertSame($expected, (new Quantity($input))->value());
    }

    public static function validValues(): array
    {
        return [
            'whole number' => ['1', '1.000'],
            'already 3dp' => ['1.000', '1.000'],
            'half a unit' => ['0.500', '0.500'],
            'three-quarter with 2dp input' => ['1.25', '1.250'],
            'zero' => ['0', '0.000'],
        ];
    }

    /** @dataProvider invalidValues */
    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_precision_or_input(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Quantity($input);
    }

    public static function invalidValues(): array
    {
        return [
            'four decimal places' => ['1.2345'],
            'negative' => ['-1.000'],
            'not numeric' => ['abc'],
            'empty string' => [''],
        ];
    }

    public function test_add(): void
    {
        $result = (new Quantity('1.500'))->add(new Quantity('0.750'));

        $this->assertSame('2.250', $result->value());
    }

    public function test_subtract(): void
    {
        $result = (new Quantity('2.000'))->subtract(new Quantity('0.500'));

        $this->assertSame('1.500', $result->value());
    }

    public function test_subtract_below_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Quantity('1.000'))->subtract(new Quantity('2.000'));
    }

    public function test_comparison(): void
    {
        $a = new Quantity('1.000');
        $b = new Quantity('1.500');

        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($a->equals(new Quantity('1')));
    }

    public function test_is_zero_and_positive(): void
    {
        $this->assertTrue(Quantity::zero()->isZero());
        $this->assertTrue((new Quantity('0.001'))->isPositive());
    }
}

<?php

namespace Tests\Unit\Services\InvoiceNumbering;

use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Pure formatting logic only -- no database. See tests/Database/InvoiceSeriesAllocatorTest.php for allocation behavior. */
class InvoiceSeriesAllocatorFormatTest extends TestCase
{
    public static function formattingCases(): array
    {
        return [
            'single digit' => [1, '000001'],
            'two digits' => [2, '000002'],
            'forty-two' => [42, '000042'],
            'exactly six digits' => [999999, '999999'],
            'seven digits, never truncated' => [1000000, '1000000'],
            'thirteen digits, digits only, no scientific notation' => [9999999999999, '9999999999999'],
        ];
    }

    /** @dataProvider formattingCases */
    #[DataProvider('formattingCases')]
    public function test_formats_serial_with_minimum_six_digit_zero_padding(int $serial, string $expected): void
    {
        $formatted = InvoiceSeriesAllocator::format($serial);

        $this->assertSame($expected, $formatted);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $formatted, 'formatted invoice numbers must be digits only');
        $this->assertGreaterThanOrEqual(6, strlen($formatted), 'formatted invoice numbers must be at least six characters');
    }

    public function test_zero_serial_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceSeriesAllocator::format(0);
    }

    public function test_negative_serial_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceSeriesAllocator::format(-1);
    }

    /** BIGINT's practical ceiling on a 64-bit PHP build -- proves no float coercion/scientific notation at the boundary. */
    public function test_large_boundary_value_has_no_scientific_notation(): void
    {
        $formatted = InvoiceSeriesAllocator::format(PHP_INT_MAX);

        $this->assertSame((string) PHP_INT_MAX, $formatted);
        $this->assertStringNotContainsString('E', strtoupper($formatted));
        $this->assertStringNotContainsString('.', $formatted);
    }
}

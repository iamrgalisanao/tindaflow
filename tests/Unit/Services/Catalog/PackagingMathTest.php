<?php

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\PackagingMath;
use PHPUnit\Framework\TestCase;

/**
 * Stage 29: the two conversions behind a pack receipt, in exact decimals. A pack count that does not come out as a whole
 * number of thousandths of a unit would silently lose stock, so it is refused rather than rounded.
 */
class PackagingMathTest extends TestCase
{
    public function test_packs_become_units(): void
    {
        $this->assertSame('120.000', PackagingMath::units('5', '24.000'));
        $this->assertSame('12.000', PackagingMath::units('0.5', '24'));
        $this->assertSame('2.500', PackagingMath::units('5', '0.5'));
        $this->assertSame('3.000', PackagingMath::units('3.000', '1.000'));
        $this->assertSame('0.001', PackagingMath::units('1', '0.001'));
    }

    public function test_a_count_that_is_not_a_whole_number_of_thousandths_is_refused(): void
    {
        $this->assertNull(PackagingMath::units('0.001', '0.5'), '0.0005 units cannot be stored');
        $this->assertNull(PackagingMath::units('1.5', '0.333'), '0.4995 units cannot be stored');
    }

    public function test_more_than_the_ledger_can_hold_is_refused(): void
    {
        $this->assertNull(PackagingMath::units('9999999', '24'));
        $this->assertSame('9999999.000', PackagingMath::units('9999999', '1'));
        $this->assertNull(PackagingMath::units('10000000', '1'));
    }

    public function test_the_unit_cost_is_the_pack_cost_divided_and_rounded_half_up_to_two_decimals(): void
    {
        $this->assertSame('4.81', PackagingMath::unitCost('1153.92', '240'), '4.808');
        $this->assertSame('3.33', PackagingMath::unitCost('10.00', '3'), '3.3333');
        $this->assertSame('5.01', PackagingMath::unitCost('10.01', '2'), '5.005 rounds up');
        $this->assertSame('2.68', PackagingMath::unitCost('2.675', '1'), 'exactly half rounds up');
        $this->assertSame('99.99', PackagingMath::unitCost('99.99', '1'));
        $this->assertSame('40.00', PackagingMath::unitCost('960.00', '24.000'));
        $this->assertSame('0.00', PackagingMath::unitCost('0.01', '3'));
    }

    public function test_the_rounded_unit_cost_does_not_always_give_the_pack_cost_back(): void
    {
        // The reason a receipt also records the pack cost itself: the pack cost is the truth, the unit cost is derived.
        $unitCost = PackagingMath::unitCost('1153.92', '240');

        $this->assertNotSame('1153.92', bcmul($unitCost, '240', 2));
        $this->assertSame('1154.40', bcmul($unitCost, '240', 2));
    }
}

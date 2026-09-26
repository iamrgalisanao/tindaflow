<?php

namespace Tests\Unit\Domain\Financial;

use App\Domain\Financial\NetTenderAllocator;
use App\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * Payments are stored as TENDERED; what a sale collected is the tender less the change handed back, and change
 * is handed back in cash (docs/06-backend/stage-9-shift-close-fiscal-day-close.md, "Change is not collected").
 */
class NetTenderAllocatorTest extends TestCase
{
    /**
     * @param  list<array{0: string, 1: string}>  $payments  [method, amount]
     * @return list<string>
     */
    private function net(string $grandTotal, array $payments): array
    {
        $net = (new NetTenderAllocator)->allocate(
            Money::fromApiString($grandTotal),
            array_map(fn (array $p) => ['method' => $p[0], 'amount' => Money::fromApiString($p[1])], $payments),
        );

        return array_map(fn (Money $m) => $m->toApiString(), $net);
    }

    public function test_a_cash_sale_paid_with_a_bigger_note_collects_only_the_total(): void
    {
        $this->assertSame(['107.00'], $this->net('107.00', [['CASH', '200.00']]));
    }

    public function test_an_exact_payment_is_left_alone(): void
    {
        $this->assertSame(['66.00', '40.00'], $this->net('106.00', [['CASH', '66.00'], ['GCASH', '40.00']]));
    }

    public function test_change_comes_off_the_cash_part_of_a_split_tender(): void
    {
        // 170.00 tendered against 150.00: the 20.00 change is cash, so GCASH stays 50.00 and CASH is 100.00.
        $this->assertSame(['50.00', '100.00'], $this->net('150.00', [['GCASH', '50.00'], ['CASH', '120.00']]));
    }

    public function test_change_larger_than_the_cash_tendered_falls_on_the_non_cash_payments_last_first(): void
    {
        // 130.00 tendered on 100.00 = 30.00 change; only 10.00 of cash, so the other 20.00 comes off the last non-cash row.
        $this->assertSame(['60.00', '0.00', '40.00'], $this->net('100.00', [['GCASH', '60.00'], ['CASH', '10.00'], ['MAYA', '60.00']]));
    }

    public function test_an_over_tendered_non_cash_sale_collects_only_the_total(): void
    {
        $this->assertSame(['100.00'], $this->net('100.00', [['GCASH', '150.00']]));
    }

    public function test_the_net_of_every_payment_always_adds_up_to_the_grand_total(): void
    {
        $net = $this->net('123.45', [['CASH', '100.00'], ['CARD', '50.00'], ['CASH', '20.05']]);

        $sum = '0.00';
        foreach ($net as $amount) {
            $sum = bcadd($sum, $amount, 2);
        }

        $this->assertSame('123.45', $sum);
    }
}

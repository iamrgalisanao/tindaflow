<?php

namespace Tests\Unit\Support;

use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use LogicException;
use Tests\TestCase;

class GlobalLockOrderTest extends TestCase
{
    public function test_checkout_order_is_accepted(): void
    {
        $order = new GlobalLockOrder;

        $order->acquire(LockableResource::Shift);
        $order->acquire(LockableResource::FiscalDay);
        $order->acquire(LockableResource::InvoiceSeries);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_void_completion_order_is_accepted(): void
    {
        $order = new GlobalLockOrder;

        $order->acquire(LockableResource::Shift);
        $order->acquire(LockableResource::FiscalDay);
        $order->acquire(LockableResource::Sale);

        $this->addToAssertionCount(1);
    }

    public function test_refund_completion_order_with_multiple_sale_items_ascending_is_accepted(): void
    {
        $order = new GlobalLockOrder;

        $order->acquire(LockableResource::Shift);
        $order->acquire(LockableResource::FiscalDay);
        $order->acquire(LockableResource::Sale);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 1);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 2);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 5);

        $this->addToAssertionCount(1);
    }

    public function test_an_operation_may_skip_levels_it_does_not_need(): void
    {
        $order = new GlobalLockOrder;

        // Shift close: only needs its own shift.
        $order->acquire(LockableResource::Shift);

        $this->addToAssertionCount(1);
    }

    public function test_acquiring_out_of_order_throws(): void
    {
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::FiscalDay);

        $this->expectException(LogicException::class);
        $order->acquire(LockableResource::Shift);
    }

    public function test_invoice_series_before_shift_throws(): void
    {
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::InvoiceSeries);

        $this->expectException(LogicException::class);
        $order->acquire(LockableResource::Shift);
    }

    public function test_sale_item_locks_out_of_ascending_order_throws(): void
    {
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::Sale);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 5);

        $this->expectException(LogicException::class);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 2);
    }

    public function test_sale_item_without_a_tie_breaker_throws(): void
    {
        $order = new GlobalLockOrder;

        $this->expectException(LogicException::class);
        $order->acquire(LockableResource::SaleItem);
    }

    public function test_reset_allows_a_fresh_sequence(): void
    {
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::InvoiceSeries);

        $order->reset();
        $order->acquire(LockableResource::Shift); // would have thrown before reset()

        $this->addToAssertionCount(1);
    }

    public function test_the_credit_orders_are_accepted_with_the_employee_between_fiscal_day_and_sale(): void
    {
        // Checkout of a credit sale, and void / refund of one (ADR-016 slice 3.2): shift, fiscal day, employee, sale, ..., slip counter.
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::Shift);
        $order->acquire(LockableResource::FiscalDay);
        $order->acquire(LockableResource::Employee);
        $order->acquire(LockableResource::Sale);
        $order->acquire(LockableResource::SaleItem, tieBreaker: 1);
        $order->acquire(LockableResource::InvoiceSeries);

        $slip = new GlobalLockOrder;
        $slip->acquire(LockableResource::Shift);
        $slip->acquire(LockableResource::Employee);
        $slip->acquire(LockableResource::SlipCounter);

        $this->addToAssertionCount(1);
    }

    public function test_the_employee_cannot_be_locked_after_the_sale_or_before_the_fiscal_day(): void
    {
        $afterSale = new GlobalLockOrder;
        $afterSale->acquire(LockableResource::Sale);
        $this->expectException(LogicException::class);
        $afterSale->acquire(LockableResource::Employee);
    }

    public function test_the_employee_cannot_be_locked_before_the_fiscal_day(): void
    {
        $order = new GlobalLockOrder;
        $order->acquire(LockableResource::Employee);
        $this->expectException(LogicException::class);
        $order->acquire(LockableResource::FiscalDay);
    }

    public function test_the_order_values_are_the_documented_ones(): void
    {
        $this->assertSame(
            ['shift' => 0, 'fiscal_day' => 1, 'employee' => 2, 'sale' => 3, 'sale_item' => 4, 'invoice_series' => 5, 'slip_counter' => 6],
            collect(LockableResource::cases())->mapWithKeys(fn (LockableResource $resource) => [$resource->value => $resource->order()])->all(),
        );
    }
}

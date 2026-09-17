<?php

namespace Tests\Database;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleVoid;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;

// Confirms every Stage 5 factory (migration-plan.md §5) actually
// produces a persistable, constraint-satisfying row -- not merely that
// the factory class exists. Each assertion is a real INSERT against
// PostgreSQL.
class FactorySmokeTest extends PostgresSchemaTestCase
{
    public function test_core_factories_produce_persistable_rows(): void
    {
        $this->assertInstanceOf(Store::class, Store::factory()->create());
        $this->assertInstanceOf(User::class, User::factory()->create());
        $this->assertInstanceOf(Terminal::class, Terminal::factory()->create());
        $this->assertInstanceOf(Product::class, Product::factory()->create());
        $this->assertInstanceOf(Shift::class, Shift::factory()->create());
        $this->assertInstanceOf(Sale::class, Sale::factory()->create());
        $this->assertInstanceOf(SaleItem::class, SaleItem::factory()->create());
        $this->assertInstanceOf(Payment::class, Payment::factory()->create());
        $this->assertInstanceOf(Invoice::class, Invoice::factory()->create());
        $this->assertInstanceOf(Refund::class, Refund::factory()->completed()->create());
        $this->assertInstanceOf(SaleVoid::class, SaleVoid::factory()->voided()->create());
    }

    public function test_shift_factory_produces_coherent_terminal_fiscal_day_graph(): void
    {
        $shift = Shift::factory()->create();

        $this->assertSame($shift->terminal_id, $shift->fiscalDay->terminal_id);
    }

    public function test_sale_factory_produces_coherent_shift_graph(): void
    {
        $sale = Sale::factory()->create();

        $this->assertSame($sale->terminal_id, $sale->shift->terminal_id);
        $this->assertSame($sale->fiscal_day_id, $sale->shift->fiscal_day_id);
    }
}

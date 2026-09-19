<?php

namespace App\Services\Reports;

use App\Domain\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The 15 openapi.yaml Reports operations. Every query is a read-only
 * aggregate over already-committed records -- no report ever mutates
 * anything, and (per operation-inventory.md) none has a domain-specific
 * failure mode, only auth. "Sales" aggregates (gross_sales, payment
 * breakdowns, tax totals) uniformly exclude VOIDED sales, matching the
 * Z-Reading precedent (Stage 9): a voided transaction is not revenue.
 * `reportSalesByDateRange`/`reportVoids`/`reportRefunds` are raw
 * listings and deliberately show every status/outcome instead, since
 * that visibility is the report's own point.
 */
final class ReportQueryService
{
    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function dailySalesSummary(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('sales')
            ->join('fiscal_days', 'fiscal_days.id', '=', 'sales.fiscal_day_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->selectRaw('fiscal_days.business_date as business_date')
            ->selectRaw('SUM(sales.subtotal) as gross_sales')
            ->selectRaw('SUM(sales.discount_total) as discount_total')
            ->selectRaw('SUM(sales.taxable_sales) as taxable_sales')
            ->selectRaw('SUM(sales.vat_exempt_sales) as vat_exempt_sales')
            ->selectRaw('SUM(sales.zero_rated_sales) as zero_rated_sales')
            ->selectRaw('SUM(sales.vat_amount) as vat_amount')
            ->selectRaw('SUM(sales.grand_total) as grand_total')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(sales.non_vat_sales) as non_vat_sales')
            ->groupBy('fiscal_days.business_date')
            ->orderBy('fiscal_days.business_date')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'business_date' => $row->business_date,
            'gross_sales' => $this->money($row->gross_sales),
            'discount_total' => $this->money($row->discount_total),
            'taxable_sales' => $this->money($row->taxable_sales),
            'vat_exempt_sales' => $this->money($row->vat_exempt_sales),
            'zero_rated_sales' => $this->money($row->zero_rated_sales),
            'vat_amount' => $this->money($row->vat_amount),
            'grand_total' => $this->money($row->grand_total),
            'transaction_count' => (int) $row->transaction_count,
            'non_vat_sales' => $this->money($row->non_vat_sales),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['gross_sales', 'discount_total', 'grand_total'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function salesByDateRange(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('sales')
            ->leftJoin('invoices', 'invoices.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->select([
                'sales.sold_at', 'invoices.invoice_number', 'sales.transaction_number',
                'sales.terminal_id', 'sales.cashier_id', 'sales.subtotal',
                'sales.discount_total', 'sales.grand_total', 'sales.status',
            ])
            ->orderBy('sales.sold_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'sold_at' => Carbon::parse($row->sold_at)->toJSON(),
            'invoice_number' => $row->invoice_number,
            'transaction_number' => $row->transaction_number,
            'terminal_id' => $row->terminal_id,
            'cashier_id' => $row->cashier_id,
            'subtotal' => $this->money($row->subtotal),
            'discount_total' => $this->money($row->discount_total),
            'grand_total' => $this->money($row->grand_total),
            'status' => $row->status,
        ])->all();

        return ['rows' => $mapped, 'summary' => ['transaction_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function salesByProduct(string $storeId, ?Carbon $from, ?Carbon $to, ?string $productId): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->when($productId, fn ($q) => $q->where('sale_items.product_id', $productId))
            ->selectRaw('sale_items.product_id as product_id, products.sku as sku, products.name as product_name')
            ->selectRaw('SUM(sale_items.quantity) as quantity_sold')
            ->selectRaw('SUM(sale_items.gross_line_amount) as gross_sales')
            ->selectRaw('SUM(sale_items.net_line_amount) as net_sales')
            ->selectRaw('SUM(sale_items.tax_amount) as tax_amount')
            ->groupBy('sale_items.product_id', 'products.sku', 'products.name')
            ->orderByDesc('net_sales')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'sku' => $row->sku,
            'product_name' => $row->product_name,
            'quantity_sold' => (string) $row->quantity_sold,
            'gross_sales' => $this->money($row->gross_sales),
            'net_sales' => $this->money($row->net_sales),
            'tax_amount' => $this->money($row->tax_amount),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['gross_sales', 'net_sales', 'tax_amount'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function salesByCategory(string $storeId, ?Carbon $from, ?Carbon $to, ?string $categoryId): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw('products.category_id as category_id, categories.name as category_name')
            ->selectRaw('SUM(sale_items.quantity) as quantity_sold')
            ->selectRaw('SUM(sale_items.gross_line_amount) as gross_sales')
            ->selectRaw('SUM(sale_items.net_line_amount) as net_sales')
            ->groupBy('products.category_id', 'categories.name')
            ->orderByDesc('net_sales')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'category_id' => $row->category_id,
            'category_name' => $row->category_name,
            'quantity_sold' => (string) $row->quantity_sold,
            'gross_sales' => $this->money($row->gross_sales),
            'net_sales' => $this->money($row->net_sales),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['gross_sales', 'net_sales'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function salesByCashier(string $storeId, ?Carbon $from, ?Carbon $to, ?string $cashierId): array
    {
        $sales = DB::table('sales')
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->where('sales.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->when($cashierId, fn ($q) => $q->where('sales.cashier_id', $cashierId))
            ->selectRaw('sales.cashier_id as cashier_id, users.name as cashier_name')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("SUM(CASE WHEN sales.status != 'VOIDED' THEN sales.grand_total ELSE 0 END) as gross_sales")
            ->selectRaw("COUNT(DISTINCT CASE WHEN sales.status = 'VOIDED' THEN sales.id END) as void_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN sales.status IN ('PARTIALLY_REFUNDED', 'REFUNDED') THEN sales.id END) as refund_count")
            ->groupBy('sales.cashier_id', 'users.name')
            ->orderByDesc('gross_sales')
            ->get();

        $mapped = $sales->map(fn ($row) => [
            'cashier_id' => $row->cashier_id,
            'cashier_name' => $row->cashier_name,
            'transaction_count' => (int) $row->transaction_count,
            'gross_sales' => $this->money($row->gross_sales),
            'void_count' => (int) $row->void_count,
            'refund_count' => (int) $row->refund_count,
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['gross_sales'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function salesByPaymentMethod(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->selectRaw('payments.method as payment_method')
            ->selectRaw('COUNT(DISTINCT payments.sale_id) as transaction_count')
            ->selectRaw('SUM(payments.amount) as total_amount')
            ->groupBy('payments.method')
            ->orderByDesc('total_amount')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'payment_method' => $row->payment_method,
            'transaction_count' => (int) $row->transaction_count,
            'total_amount' => $this->money($row->total_amount),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['total_amount'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function taxBreakdown(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('sales')
            ->join('fiscal_days', 'fiscal_days.id', '=', 'sales.fiscal_day_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->selectRaw('fiscal_days.business_date as business_date')
            ->selectRaw('SUM(sales.taxable_sales) as taxable_sales')
            ->selectRaw('SUM(sales.vat_exempt_sales) as vat_exempt_sales')
            ->selectRaw('SUM(sales.zero_rated_sales) as zero_rated_sales')
            ->selectRaw('SUM(sales.vat_amount) as vat_amount')
            ->selectRaw('SUM(sales.non_vat_sales) as non_vat_sales')
            ->groupBy('fiscal_days.business_date')
            ->orderBy('fiscal_days.business_date')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'business_date' => $row->business_date,
            'taxable_sales' => $this->money($row->taxable_sales),
            'vat_exempt_sales' => $this->money($row->vat_exempt_sales),
            'zero_rated_sales' => $this->money($row->zero_rated_sales),
            'vat_amount' => $this->money($row->vat_amount),
            'non_vat_sales' => $this->money($row->non_vat_sales),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['taxable_sales', 'vat_amount', 'non_vat_sales'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function voids(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('voids')
            ->join('sales', 'sales.id', '=', 'voids.sale_id')
            ->leftJoin('invoices', 'invoices.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('voids.requested_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('voids.requested_at', '<=', $to))
            ->select([
                'voids.id as void_id', 'voids.sale_id', 'invoices.invoice_number',
                'voids.requested_by', 'voids.approved_by', 'voids.reason',
                'voids.terminal_id', 'voids.fiscal_day_id', 'voids.resolved_at',
                'sales.grand_total as sale_grand_total', 'voids.status', 'voids.requested_at',
            ])
            ->orderBy('voids.requested_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'void_id' => $row->void_id,
            'sale_id' => $row->sale_id,
            'invoice_number' => $row->invoice_number,
            'requested_by' => $row->requested_by,
            'approved_by' => $row->approved_by,
            'reason' => $row->reason,
            'terminal_id' => $row->terminal_id,
            'fiscal_day_id' => $row->fiscal_day_id,
            'resolved_at' => $row->resolved_at === null ? null : Carbon::parse($row->resolved_at)->toJSON(),
            'sale_grand_total' => $this->money($row->sale_grand_total),
            // JSON-only, appended after the pinned CSV columns (csv-export-contract.md is unaffected):
            // without these a REJECTED/REQUESTED void is indistinguishable from an executed one.
            'status' => $row->status,
            'requested_at' => Carbon::parse($row->requested_at)->toJSON(),
        ])->all();

        return ['rows' => $mapped, 'summary' => ['void_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function refunds(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->leftJoin('invoices', 'invoices.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('refunds.requested_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('refunds.requested_at', '<=', $to))
            ->select([
                'refunds.id as refund_id', 'refunds.sale_id', 'invoices.invoice_number',
                'refunds.requested_by', 'refunds.approved_by', 'refunds.reason',
                'refunds.terminal_id', 'refunds.fiscal_day_id', 'refunds.refunded_at',
                'refunds.refund_total', 'refunds.status', 'refunds.requested_at',
            ])
            ->orderBy('refunds.requested_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'refund_id' => $row->refund_id,
            'sale_id' => $row->sale_id,
            'invoice_number' => $row->invoice_number,
            'requested_by' => $row->requested_by,
            'approved_by' => $row->approved_by,
            'reason' => $row->reason,
            'terminal_id' => $row->terminal_id,
            'fiscal_day_id' => $row->fiscal_day_id,
            'refunded_at' => $row->refunded_at === null ? null : Carbon::parse($row->refunded_at)->toJSON(),
            'refund_total' => $row->refund_total === null ? null : $this->money($row->refund_total),
            'status' => $row->status,
            'requested_at' => Carbon::parse($row->requested_at)->toJSON(),
        ])->all();

        return ['rows' => $mapped, 'summary' => ['refund_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function discounts(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('sales')
            ->leftJoin('invoices', 'invoices.sale_id', '=', 'sales.id')
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'VOIDED')
            ->where('sales.discount_total', '>', 0)
            ->when($from, fn ($q) => $q->where('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sales.sold_at', '<=', $to))
            ->selectRaw('sales.id as sale_id, sales.sold_at, invoices.invoice_number')
            ->selectRaw('COALESCE(SUM(sale_items.line_discount_amount), 0.00) as line_discount_total')
            ->selectRaw('MAX(sales.order_level_discount_amount) as order_discount_total')
            ->selectRaw('MAX(sales.discount_total) as discount_total')
            ->groupBy('sales.id', 'sales.sold_at', 'invoices.invoice_number')
            ->orderBy('sales.sold_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'sold_at' => Carbon::parse($row->sold_at)->toJSON(),
            'invoice_number' => $row->invoice_number,
            'line_discount_total' => $this->money($row->line_discount_total),
            'order_discount_total' => $this->money($row->order_discount_total),
            'discount_total' => $this->money($row->discount_total),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['discount_total'])];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function inventoryOnHand(string $storeId): array
    {
        $rows = DB::table('stock_balances')
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('products.store_id', $storeId)
            ->where('products.track_inventory', true) // same rule as lowStock: an untracked product's ledger is not stock
            ->select([
                'stock_balances.product_id', 'products.sku', 'products.name as product_name',
                'stock_balances.location_id', 'stock_balances.quantity_on_hand', 'products.reorder_level',
            ])
            ->orderBy('products.name')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'sku' => $row->sku,
            'product_name' => $row->product_name,
            'location_id' => $row->location_id,
            'quantity_on_hand' => (string) $row->quantity_on_hand,
            'reorder_level' => $row->reorder_level === null ? null : (string) $row->reorder_level,
        ])->all();

        return ['rows' => $mapped, 'summary' => ['product_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function lowStock(string $storeId): array
    {
        $rows = DB::table('stock_balances')
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('products.store_id', $storeId)
            ->where('products.track_inventory', true)
            ->whereNotNull('products.reorder_level')
            ->selectRaw('stock_balances.product_id as product_id, products.sku as sku, products.name as product_name, products.reorder_level as reorder_level')
            ->selectRaw('SUM(stock_balances.quantity_on_hand) as quantity_on_hand')
            ->groupBy('stock_balances.product_id', 'products.sku', 'products.name', 'products.reorder_level')
            ->havingRaw('SUM(stock_balances.quantity_on_hand) < products.reorder_level')
            ->orderBy('products.name')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'sku' => $row->sku,
            'product_name' => $row->product_name,
            'quantity_on_hand' => (string) $row->quantity_on_hand,
            'reorder_level' => (string) $row->reorder_level,
            'shortfall' => bcsub((string) $row->reorder_level, (string) $row->quantity_on_hand, 3),
        ])->all();

        return ['rows' => $mapped, 'summary' => ['product_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function inventoryMovement(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('products.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('stock_movements.occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('stock_movements.occurred_at', '<=', $to))
            ->select([
                'stock_movements.occurred_at', 'stock_movements.product_id', 'products.sku',
                'stock_movements.movement_type', 'stock_movements.quantity',
                'stock_movements.reference_type', 'stock_movements.reference_id',
                'stock_movements.reason', 'stock_movements.created_by',
            ])
            ->orderBy('stock_movements.occurred_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'occurred_at' => Carbon::parse($row->occurred_at)->toJSON(),
            'product_id' => $row->product_id,
            'sku' => $row->sku,
            'movement_type' => $row->movement_type,
            'quantity' => (string) $row->quantity,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'reason' => $row->reason,
            'created_by' => $row->created_by,
        ])->all();

        return ['rows' => $mapped, 'summary' => ['movement_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function shifts(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('shifts')
            ->join('terminals', 'terminals.id', '=', 'shifts.terminal_id')
            ->where('terminals.store_id', $storeId)
            ->when($from, fn ($q) => $q->where('shifts.opened_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('shifts.opened_at', '<=', $to))
            ->select([
                'shifts.id as shift_id', 'shifts.terminal_id', 'shifts.cashier_id',
                'shifts.opened_at', 'shifts.closed_at', 'shifts.opening_cash',
                'shifts.declared_cash', 'shifts.expected_cash', 'shifts.variance',
            ])
            ->orderBy('shifts.opened_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'shift_id' => $row->shift_id,
            'terminal_id' => $row->terminal_id,
            'cashier_id' => $row->cashier_id,
            'opened_at' => Carbon::parse($row->opened_at)->toJSON(),
            'closed_at' => $row->closed_at === null ? null : Carbon::parse($row->closed_at)->toJSON(),
            'opening_cash' => $this->money($row->opening_cash),
            'declared_cash' => $row->declared_cash === null ? null : $this->money($row->declared_cash),
            'expected_cash' => $row->expected_cash === null ? null : $this->money($row->expected_cash),
            'variance' => $row->variance === null ? null : $this->money($row->variance),
        ])->all();

        return ['rows' => $mapped, 'summary' => ['shift_count' => count($mapped)]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>} */
    public function cashVariance(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('shifts')
            ->join('terminals', 'terminals.id', '=', 'shifts.terminal_id')
            ->where('terminals.store_id', $storeId)
            ->where('shifts.status', 'CLOSED')
            ->when($from, fn ($q) => $q->where('shifts.closed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('shifts.closed_at', '<=', $to))
            ->select([
                'shifts.id as shift_id', 'shifts.terminal_id', 'shifts.cashier_id',
                'shifts.closed_at', 'shifts.expected_cash', 'shifts.declared_cash', 'shifts.variance',
            ])
            ->orderBy('shifts.closed_at')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'shift_id' => $row->shift_id,
            'terminal_id' => $row->terminal_id,
            'cashier_id' => $row->cashier_id,
            'closed_at' => Carbon::parse($row->closed_at)->toJSON(),
            'expected_cash' => $this->money($row->expected_cash),
            'declared_cash' => $this->money($row->declared_cash),
            'variance' => $this->money($row->variance),
        ])->all();

        return ['rows' => $mapped, 'summary' => $this->sumColumns($mapped, ['variance'])];
    }

    private function money(mixed $rawSum): string
    {
        return Money::fromApiString((string) ($rawSum ?? '0.00'))->toApiString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $columns
     * @return array<string, string>
     */
    private function sumColumns(array $rows, array $columns): array
    {
        $totals = [];
        foreach ($columns as $column) {
            $total = Money::zero();
            foreach ($rows as $row) {
                $total = $total->add(Money::fromApiString((string) ($row[$column] ?? '0.00')));
            }
            $totals[$column] = $total->toApiString();
        }

        return $totals;
    }
}

/**
 * Declarative registry for the 15 openapi.yaml Reports operations -- one
 * shared viewer renders all of them from this config (the architecture
 * the Stitch "15-Report Code Schema" spec asks for). Unlike that spec,
 * every `key` here is a REAL response field pinned by docs/05-api/csv-
 * export-contract.md (or, for voids/refunds, the two JSON-only additions
 * `status`/`requested_at`), every URL slug is the real API slug, and
 * `total: true` is set only where summing the displayed rows is
 * actually meaningful:
 *   - never on sales-by-date-range / voids / refunds (raw listings that
 *     deliberately include VOIDED/rejected rows -- a footer total would
 *     silently count them);
 *   - never on transaction/payment counts that can double-count (a sale
 *     paid by two methods appears under both);
 *   - never on quantities across differently-measured products.
 * Server-computed figures come from the response's own `summary`.
 *
 * Optional per-report presentation fields (all display-only, computed in
 * the browser from the loaded rows -- nothing here changes the API contract):
 *   entity        single-select filter (see EntityCombobox)
 *   statusFilter  status chips with counts over the loaded rows
 *   derivedSummary(rows) extra summary cards
 *   statusLegend  lifecycle legend under the table
 *   defaultSort   initial client-side sort
 *   emptyState    replaces the generic "no rows" panel
 *   note          muted line under the summary cards
 *   mobileBadge / mobileMore / moreLabel / mobileEmphasis / mobileTotals
 *                 how the table reflows to key-value cards below 768px
 */

import { stockTier } from './formatters';

export const REPORT_CATEGORIES = [
    { id: 'sales', label: 'Sales Reports' },
    { id: 'tax', label: 'Tax & VAT' },
    { id: 'exceptions', label: 'Exceptions & Audits' },
    { id: 'inventory', label: 'Inventory & Stock' },
    { id: 'cash', label: 'Cash & Shifts' },
];

const dateRange = (defaultPreset) => ({ type: 'date_range', defaultPreset });

const col = (key, label, format, extra = {}) => ({ key, label, format, ...extra });
const right = { align: 'right' };
const center = { align: 'center' };

// stock_movements.quantity is always positive (DB check); direction lives in movement_type.
const OUTFLOW_MOVEMENTS = new Set(['SALE', 'STOCK_ADJUSTMENT_OUT', 'DAMAGE', 'EXPIRED', 'TRANSFER_OUT']);

const countStatus = (rows, status) => rows.filter((row) => row.status === status).length;

/** Void requests end VOIDED; refund requests end COMPLETED. Both can be REJECTED. */
function outcomeReport(finalStatus, finalLabel, legendItems, legendNote) {
    return {
        statusFilter: {
            key: 'status',
            statuses: [
                { id: 'REQUESTED', label: 'Requested', tone: 'slate' },
                { id: 'APPROVED', label: 'Approved', tone: 'amber' },
                { id: 'REJECTED', label: 'Rejected', tone: 'rose' },
                { id: finalStatus, label: finalLabel, tone: 'emerald' },
            ],
            note: 'Status filter applies to the rows already loaded. CSV export always includes every status.',
        },
        derivedSummary: (rows) => [
            { label: finalLabel, value: countStatus(rows, finalStatus), tone: 'emerald' },
            { label: 'Rejected', value: countStatus(rows, 'REJECTED'), tone: 'rose' },
            {
                label: 'Still pending',
                value: countStatus(rows, 'REQUESTED') + countStatus(rows, 'APPROVED'),
                sub: 'requested + approved',
                tone: 'amber',
            },
        ],
        statusLegend: {
            title: 'Status legend',
            flow: ['REQUESTED', 'APPROVED', finalStatus],
            alternative: 'REJECTED',
            items: legendItems,
            note: legendNote,
        },
        rowTone: (row) => (row.status === 'REJECTED' ? 'opacity-60' : ''),
    };
}

export const REPORTS = [
    {
        slug: 'daily-sales-summary',
        title: 'Daily Sales Summary',
        description: 'Per business day: gross sales, discounts, the VAT category split, and grand total. Voided sales are excluded.',
        category: 'sales',
        filters: [dateRange('this_week')],
        summary: [
            { key: 'gross_sales', label: 'Gross sales', format: 'currency' },
            { key: 'discount_total', label: 'Discounts', format: 'currency' },
            { key: 'grand_total', label: 'Grand total', format: 'currency' },
        ],
        columns: [
            col('business_date', 'Business date', 'date', { sortable: true }),
            col('transaction_count', 'Txns', 'integer', { ...right, total: 'integer' }),
            col('gross_sales', 'Gross sales', 'currency', { ...right, total: 'money' }),
            col('discount_total', 'Discounts', 'currency', { ...right, total: 'money' }),
            col('taxable_sales', 'Taxable', 'currency', { ...right, total: 'money' }),
            col('vat_exempt_sales', 'VAT-exempt', 'currency', { ...right, total: 'money' }),
            col('zero_rated_sales', 'Zero-rated', 'currency', { ...right, total: 'money' }),
            col('vat_amount', 'VAT', 'currency', { ...right, total: 'money' }),
            col('non_vat_sales', 'Non-VAT', 'currency', { ...right, total: 'money' }),
            col('grand_total', 'Grand total', 'currency', { ...right, total: 'money' }),
        ],
        mobileBadge: { key: 'transaction_count', suffix: ' TXNS' },
        mobileMore: ['taxable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'non_vat_sales'],
        moreLabel: 'VAT breakdown',
        mobileEmphasis: 'grand_total',
        mobileTotals: ['gross_sales', 'grand_total'],
        exportPrefix: 'daily_sales_summary',
    },
    {
        slug: 'sales-by-date-range',
        title: 'Sales by Date Range',
        description: 'Transaction-by-transaction journal. Every status is listed, including voided and refunded sales.',
        category: 'sales',
        filters: [dateRange('this_week')],
        summary: [{ key: 'transaction_count', label: 'Transactions', format: 'integer' }],
        columns: [
            col('sold_at', 'Sold at', 'datetime', { sortable: true }),
            col('invoice_number', 'Invoice #', 'mono_id'),
            col('transaction_number', 'Txn #', 'mono_id'),
            col('terminal_id', 'Terminal', 'mono_id', center),
            col('cashier_id', 'Cashier', 'mono_id', center),
            col('subtotal', 'Subtotal', 'currency', right),
            col('discount_total', 'Discount', 'currency', right),
            col('grand_total', 'Total', 'currency', right),
            col('status', 'Status', 'status_badge', center),
        ],
        rowTone: (row) => (row.status === 'VOIDED' ? 'bg-rose-950/20 text-slate-500 line-through' : ''),
        mobileBadge: { key: 'status' },
        mobileMore: ['transaction_number', 'terminal_id', 'cashier_id', 'subtotal', 'discount_total'],
        moreLabel: 'details',
        mobileEmphasis: 'grand_total',
        exportPrefix: 'sales_by_date_range',
    },
    {
        slug: 'sales-by-product',
        title: 'Sales by Product',
        description: 'Units, gross and net sales, and tax per product. Voided sales are excluded.',
        category: 'sales',
        filters: [
            dateRange('this_month'),
            {
                type: 'entity',
                key: 'product_id',
                label: 'Product',
                noun: 'products',
                searchPlaceholder: 'Search SKU or name',
                optionValue: 'product_id',
                optionCode: (r) => r.sku,
                optionName: (r) => r.product_name,
            },
        ],
        summary: [
            { key: 'gross_sales', label: 'Gross sales', format: 'currency' },
            { key: 'net_sales', label: 'Net sales', format: 'currency' },
            { key: 'tax_amount', label: 'Tax', format: 'currency' },
        ],
        columns: [
            col('sku', 'SKU', 'mono_id'),
            col('product_name', 'Product', 'text'),
            col('quantity_sold', 'Qty sold', 'quantity', right),
            col('gross_sales', 'Gross sales', 'currency', { ...right, total: 'money' }),
            col('net_sales', 'Net sales', 'currency', { ...right, total: 'money' }),
            col('tax_amount', 'Tax', 'currency', { ...right, total: 'money' }),
        ],
        exportPrefix: 'sales_by_product',
    },
    {
        slug: 'sales-by-category',
        title: 'Sales by Category',
        description: 'Units and sales per merchandise category. Voided sales are excluded.',
        category: 'sales',
        filters: [
            dateRange('this_month'),
            {
                type: 'entity',
                key: 'category_id',
                label: 'Category',
                noun: 'categories',
                searchPlaceholder: 'Search category',
                optionValue: 'category_id',
                optionName: (r) => r.category_name,
            },
        ],
        summary: [
            { key: 'gross_sales', label: 'Gross sales', format: 'currency' },
            { key: 'net_sales', label: 'Net sales', format: 'currency' },
        ],
        columns: [
            col('category_name', 'Category', 'text'),
            col('quantity_sold', 'Qty sold', 'quantity', right),
            col('gross_sales', 'Gross sales', 'currency', { ...right, total: 'money' }),
            col('net_sales', 'Net sales', 'currency', { ...right, total: 'money' }),
        ],
        exportPrefix: 'sales_by_category',
    },
    {
        slug: 'sales-by-cashier',
        title: 'Sales by Cashier',
        description: 'Per cashier: transactions, gross sales, and how many of their sales were later voided or refunded.',
        category: 'sales',
        filters: [
            dateRange('this_week'),
            {
                type: 'entity',
                key: 'cashier_id',
                label: 'Cashier',
                noun: 'cashiers',
                searchPlaceholder: 'Search cashier',
                optionValue: 'cashier_id',
                optionName: (r) => r.cashier_name,
            },
        ],
        summary: [{ key: 'gross_sales', label: 'Gross sales', format: 'currency' }],
        columns: [
            col('cashier_name', 'Cashier', 'text'),
            col('cashier_id', 'ID', 'mono_id', center),
            col('transaction_count', 'Txns', 'integer', { ...right, total: 'integer' }),
            col('gross_sales', 'Gross sales', 'currency', { ...right, total: 'money' }),
            col('void_count', 'Voided', 'integer', { ...right, total: 'integer' }),
            col('refund_count', 'Refunded', 'integer', { ...right, total: 'integer' }),
        ],
        exportPrefix: 'sales_by_cashier',
    },
    {
        slug: 'sales-by-payment-method',
        title: 'Sales by Payment Method',
        description: 'Collections per tender type. A sale paid with two methods counts once under each.',
        category: 'sales',
        filters: [dateRange('this_week')],
        summary: [{ key: 'total_amount', label: 'Total collected', format: 'currency' }],
        columns: [
            col('payment_method', 'Method', 'text'),
            col('transaction_count', 'Txns', 'integer', right),
            col('total_amount', 'Total', 'currency', { ...right, total: 'money' }),
        ],
        exportPrefix: 'sales_by_payment_method',
    },
    {
        slug: 'discounts',
        title: 'Discounts',
        description: 'Sales that carried a discount, split into line-item and order-level discounts.',
        category: 'sales',
        filters: [dateRange('this_week')],
        summary: [{ key: 'discount_total', label: 'Total discounts', format: 'currency' }],
        columns: [
            col('sold_at', 'Sold at', 'datetime', { sortable: true }),
            col('invoice_number', 'Invoice #', 'mono_id'),
            col('line_discount_total', 'Line discounts', 'currency', { ...right, total: 'money' }),
            col('order_discount_total', 'Order discount', 'currency', { ...right, total: 'money' }),
            col('discount_total', 'Total discount', 'currency', { ...right, total: 'money' }),
        ],
        mobileEmphasis: 'discount_total',
        exportPrefix: 'discounts',
    },
    {
        slug: 'sales-by-hour',
        title: 'Sales by Hour',
        description: 'Transactions and revenue by hour of day, across the whole date range. Voided sales are excluded.',
        category: 'sales',
        filters: [dateRange('this_week')],
        summary: [
            { key: 'gross_sales', label: 'Gross sales', format: 'currency' },
            { key: 'grand_total', label: 'Grand total', format: 'currency' },
        ],
        columns: [
            col('hour', 'Hour', 'hour_of_day', { sortable: true }),
            col('transaction_count', 'Txns', 'integer', { ...right, total: 'integer' }),
            col('gross_sales', 'Gross sales', 'currency', { ...right, total: 'money' }),
            col('discount_total', 'Discounts', 'currency', { ...right, total: 'money' }),
            col('grand_total', 'Grand total', 'currency', { ...right, total: 'money' }),
        ],
        defaultSort: { key: 'hour', dir: 'asc' },
        mobileEmphasis: 'grand_total',
        exportPrefix: 'sales_by_hour',
    },
    {
        slug: 'gross-profit',
        title: 'Gross Profit',
        description:
            'Net sales, cost of goods sold from each line’s own cost snapshot, and the resulting gross profit and margin, per business day. A management figure (net sales is VAT-inclusive), not a VAT-exclusive accounting margin. Voided sales are excluded.',
        category: 'sales',
        filters: [dateRange('this_month')],
        summary: [
            { key: 'net_sales', label: 'Net sales', format: 'currency' },
            { key: 'cost_of_goods_sold', label: 'Cost of goods sold', format: 'currency' },
            { key: 'gross_profit', label: 'Gross profit', format: 'currency' },
        ],
        note: 'A row whose "Unknown cost" column is above zero has at least one sale line whose product had no cost on file at the time of sale -- that line costs nothing in this sum, so gross profit is overstated for that day, not understated.',
        columns: [
            col('business_date', 'Business date', 'date', { sortable: true }),
            col('transaction_count', 'Txns', 'integer', { ...right, total: 'integer' }),
            col('net_sales', 'Net sales', 'currency', { ...right, total: 'money' }),
            col('cost_of_goods_sold', 'COGS', 'currency', { ...right, total: 'money' }),
            col('gross_profit', 'Gross profit', 'currency', { ...right, total: 'money' }),
            col('gross_margin_percent', 'Margin', 'percent', right),
            col('lines_with_unknown_cost', 'Unknown cost', 'integer', { ...right, total: 'integer' }),
        ],
        mobileMore: ['cost_of_goods_sold', 'gross_margin_percent', 'lines_with_unknown_cost'],
        moreLabel: 'cost detail',
        mobileEmphasis: 'gross_profit',
        mobileTotals: ['net_sales', 'gross_profit'],
        exportPrefix: 'gross_profit',
    },
    {
        slug: 'tax-breakdown',
        title: 'Tax / VAT Breakdown',
        description: 'Per business day: taxable, VAT-exempt, and zero-rated sales, VAT, and non-VAT sales. A management report — TindaFlow V1 is not BIR-accredited.',
        category: 'tax',
        filters: [dateRange('this_month')],
        summary: [
            { key: 'taxable_sales', label: 'Taxable sales', format: 'currency' },
            { key: 'vat_amount', label: 'VAT', format: 'currency' },
            { key: 'non_vat_sales', label: 'Non-VAT sales', format: 'currency' },
        ],
        columns: [
            col('business_date', 'Business date', 'date', { sortable: true }),
            col('taxable_sales', 'Taxable', 'currency', { ...right, total: 'money' }),
            col('vat_exempt_sales', 'VAT-exempt', 'currency', { ...right, total: 'money' }),
            col('zero_rated_sales', 'Zero-rated', 'currency', { ...right, total: 'money' }),
            col('vat_amount', 'VAT', 'currency', { ...right, total: 'money' }),
            col('non_vat_sales', 'Non-VAT', 'currency', { ...right, total: 'money' }),
        ],
        mobileEmphasis: 'vat_amount',
        exportPrefix: 'tax_breakdown',
    },
    {
        slug: 'voids',
        title: 'Voids',
        description: 'Every void request in the period with its outcome — requested, approved, rejected, or voided.',
        category: 'exceptions',
        filters: [dateRange('this_week')],
        summary: [{ key: 'void_count', label: 'Void requests', format: 'integer' }],
        columns: [
            col('requested_at', 'Requested', 'datetime', { sortable: true }),
            col('void_id', 'Void', 'mono_id'),
            col('invoice_number', 'Invoice #', 'mono_id'),
            col('terminal_id', 'Terminal', 'mono_id', center),
            col('requested_by', 'Requested by', 'mono_id', center),
            col('approved_by', 'Approved by', 'mono_id', center),
            col('reason', 'Reason', 'text'),
            col('status', 'Status', 'outcome_badge', center),
            col('sale_grand_total', 'Sale total', 'currency', right),
        ],
        ...outcomeReport(
            'VOIDED',
            'Voided',
            [
                ['REQUESTED', 'A void was requested and is waiting for a decision.'],
                ['APPROVED', 'Approved, but not yet executed.'],
                ['VOIDED', 'The void was executed; the sale is now voided.'],
                ['REJECTED', 'The request was declined; the sale is unchanged.'],
            ],
            'A failed execution-time recheck leaves a void as REQUESTED.',
        ),
        mobileBadge: { key: 'status' },
        mobileMore: ['terminal_id', 'requested_by', 'approved_by'],
        moreLabel: 'details',
        mobileEmphasis: 'sale_grand_total',
        exportPrefix: 'voids',
    },
    {
        slug: 'refunds',
        title: 'Refunds',
        description: 'Every refund request in the period with its outcome, linked to the original invoice.',
        category: 'exceptions',
        filters: [dateRange('this_week')],
        summary: [{ key: 'refund_count', label: 'Refund requests', format: 'integer' }],
        columns: [
            col('requested_at', 'Requested', 'datetime', { sortable: true }),
            col('refund_id', 'Refund', 'mono_id'),
            col('invoice_number', 'Invoice #', 'mono_id'),
            col('terminal_id', 'Terminal', 'mono_id', center),
            col('requested_by', 'Requested by', 'mono_id', center),
            col('approved_by', 'Approved by', 'mono_id', center),
            col('reason', 'Reason', 'text'),
            col('status', 'Status', 'outcome_badge', center),
            col('refund_total', 'Refund total', 'currency', right),
        ],
        ...outcomeReport(
            'COMPLETED',
            'Completed',
            [
                ['REQUESTED', 'A refund was requested and is waiting for a decision.'],
                ['APPROVED', 'Approved, but not yet completed.'],
                ['COMPLETED', 'The refund was completed.'],
                ['REJECTED', 'The request was declined; the sale is unchanged.'],
            ],
            null,
        ),
        mobileBadge: { key: 'status' },
        mobileMore: ['terminal_id', 'requested_by', 'approved_by'],
        moreLabel: 'details',
        mobileEmphasis: 'refund_total',
        exportPrefix: 'refunds',
    },
    {
        slug: 'inventory-on-hand',
        title: 'Inventory On Hand',
        description: 'Current stock per product and location, against its reorder level.',
        category: 'inventory',
        filters: [],
        groupBy: { key: 'location_id', label: 'Location' },
        summary: [{ key: 'product_count', label: 'Stock lines', format: 'integer' }],
        columns: [
            col('sku', 'SKU', 'mono_id'),
            col('product_name', 'Product', 'text'),
            col('location_id', 'Location', 'mono_id'),
            col('quantity_on_hand', 'On hand', 'quantity', right),
            col('reorder_level', 'Reorder level', 'quantity', right),
        ],
        exportPrefix: 'inventory_on_hand',
    },
    {
        slug: 'low-stock',
        title: 'Low Stock',
        description: 'Products whose stock, across all locations, is below their reorder level.',
        category: 'inventory',
        filters: [],
        summary: [{ key: 'product_count', label: 'Products below reorder level', format: 'integer' }],
        derivedSummary: (rows) => {
            const tiers = rows.map((row) => stockTier(row.quantity_on_hand, row.reorder_level));
            const count = (tier) => tiers.filter((t) => t === tier).length;
            return [
                { label: 'Out of stock', value: count('OUT_OF_STOCK'), tone: 'rose' },
                { label: 'Critical', value: count('CRITICAL'), tone: 'roseSoft' },
                { label: 'Low', value: count('LOW'), tone: 'amber' },
            ];
        },
        note: 'Tiers are a display convention: out of stock = 0 on hand, critical = under 50% of the reorder level, low = the rest.',
        defaultSort: { key: 'shortfall', dir: 'desc' },
        emptyState: {
            tone: 'ok',
            title: 'Nothing is below its reorder level.',
            body: 'Every product is at or above its reorder level.',
        },
        columns: [
            col('sku', 'SKU', 'mono_id'),
            col('product_name', 'Product', 'text'),
            col('quantity_on_hand', 'On hand', 'quantity', right),
            col('reorder_level', 'Reorder level', 'quantity', right),
            col('stock_level', 'Stock level', 'stock_bar', { value: (row) => row.quantity_on_hand }),
            col('shortfall', 'Shortfall', 'shortfall', { ...right, sortable: true }),
            col('tier', 'Tier', 'stock_tier', {
                ...center,
                value: (row) => stockTier(row.quantity_on_hand, row.reorder_level),
            }),
        ],
        mobileBadge: { key: 'tier' },
        exportPrefix: 'low_stock',
    },
    {
        slug: 'inventory-movement',
        title: 'Inventory Movement',
        description: 'Append-only stock ledger: receipts, sales, returns, adjustments, and transfers.',
        category: 'inventory',
        filters: [dateRange('this_week')],
        summary: [{ key: 'movement_count', label: 'Movements', format: 'integer' }],
        columns: [
            col('occurred_at', 'When', 'datetime', { sortable: true }),
            col('sku', 'SKU', 'mono_id'),
            col('movement_type', 'Type', 'status_badge', center),
            col('quantity', 'Qty (in/out)', 'signed_quantity', {
                ...right,
                value: (row) => (OUTFLOW_MOVEMENTS.has(row.movement_type) ? `-${row.quantity}` : row.quantity),
            }),
            col('reference_type', 'Ref. type', 'text'),
            col('reference_id', 'Ref.', 'mono_id'),
            col('reason', 'Reason', 'text'),
            col('created_by', 'By', 'mono_id', center),
        ],
        mobileBadge: { key: 'movement_type' },
        mobileMore: ['reference_type', 'reference_id', 'created_by'],
        moreLabel: 'details',
        exportPrefix: 'inventory_movement',
    },
    {
        slug: 'shifts',
        title: 'Shift Report',
        description: 'Shifts opened in the period with opening float, counted and expected cash, and variance.',
        category: 'cash',
        filters: [dateRange('this_week')],
        summary: [{ key: 'shift_count', label: 'Shifts', format: 'integer' }],
        columns: [
            col('opened_at', 'Opened', 'datetime', { sortable: true }),
            col('closed_at', 'Closed', 'datetime'),
            col('shift_id', 'Shift', 'mono_id'),
            col('terminal_id', 'Terminal', 'mono_id', center),
            col('cashier_id', 'Cashier', 'mono_id', center),
            col('opening_cash', 'Opening cash', 'currency', right),
            col('declared_cash', 'Counted', 'currency', right),
            col('expected_cash', 'Expected', 'currency', right),
            col('variance', 'Variance', 'variance', right),
        ],
        mobileMore: ['closed_at', 'shift_id', 'terminal_id', 'cashier_id'],
        moreLabel: 'details',
        mobileEmphasis: 'variance',
        exportPrefix: 'shifts',
    },
    {
        slug: 'cash-variance',
        title: 'Cash Variance',
        description: 'Closed shifts only. Negative variance is a shortage (counted less than expected); positive is an overage.',
        category: 'cash',
        filters: [dateRange('this_week')],
        summary: [{ key: 'variance', label: 'Net variance', format: 'variance' }],
        columns: [
            col('closed_at', 'Closed', 'datetime', { sortable: true }),
            col('shift_id', 'Shift', 'mono_id'),
            col('terminal_id', 'Terminal', 'mono_id', center),
            col('cashier_id', 'Cashier', 'mono_id', center),
            col('expected_cash', 'Expected', 'currency', right),
            col('declared_cash', 'Counted', 'currency', right),
            col('variance', 'Variance', 'variance', { ...right, total: 'money' }),
        ],
        mobileEmphasis: 'variance',
        mobileTotals: ['variance'],
        exportPrefix: 'cash_variance',
    },
];

export const reportBySlug = (slug) => REPORTS.find((report) => report.slug === slug) ?? null;

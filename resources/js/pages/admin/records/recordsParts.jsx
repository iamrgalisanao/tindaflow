import { formatMoney, formatQuantity, shortId } from '../reports/formatters';
import { MOVEMENT_TYPES } from '../inventory/inventoryParts';

/** Audit events the application writes, in the order a person would look for them. */
export const AUDIT_TYPES = [
    { id: 'SALE_FINALIZED', label: 'Sale finalized' },
    { id: 'INVOICE_REPRINTED', label: 'Invoice reprinted' },
    { id: 'SETTINGS_CHANGED', label: 'Business details changed' },
    { id: 'PRODUCT_CREATED', label: 'Product created' },
    { id: 'PRODUCT_UPDATED', label: 'Product changed' },
    { id: 'PRODUCT_IMPORTED', label: 'Products imported' },
    { id: 'PRODUCT_BARCODE_ADDED', label: 'Barcode added' },
    { id: 'PRODUCT_BARCODE_REMOVED', label: 'Barcode removed' },
    { id: 'SALE_VOID_REQUESTED', label: 'Void requested' },
    { id: 'SALE_VOIDED', label: 'Sale voided' },
    { id: 'SALE_VOID_REJECTED', label: 'Void rejected' },
    { id: 'REFUND_REQUESTED', label: 'Refund requested' },
    { id: 'REFUND_CREATED', label: 'Refund completed' },
    { id: 'REFUND_REJECTED', label: 'Refund rejected' },
    { id: 'STOCK_ADJUSTED', label: 'Stock recorded' },
    { id: 'STOCK_COUNT_POSTED', label: 'Stock count posted' },
    { id: 'STOCK_COUNT_CANCELLED', label: 'Stock count cancelled' },
    { id: 'STOCK_TRANSFERRED', label: 'Stock transferred' },
    { id: 'CASH_IN', label: 'Cash in' },
    { id: 'CASH_OUT', label: 'Cash out' },
    { id: 'SHIFT_CLOSED', label: 'Shift closed' },
    { id: 'X_READING_GENERATED', label: 'X-Reading generated' },
    { id: 'Z_READING_GENERATED', label: 'Z-Reading generated' },
];

export const JOURNAL_TYPES = [
    { id: 'INVOICE', label: 'Invoice' },
    { id: 'VOID', label: 'Void' },
    { id: 'REFUND', label: 'Refund' },
    { id: 'X_READING', label: 'X-Reading' },
    { id: 'Z_READING', label: 'Z-Reading' },
    { id: 'SHIFT_OPENED', label: 'Shift opened' },
    { id: 'SHIFT_CLOSED', label: 'Shift closed' },
    { id: 'CASH_IN', label: 'Cash in' },
    { id: 'CASH_OUT', label: 'Cash out' },
    { id: 'STOCK_ADJUSTED', label: 'Stock adjusted' },
];

const humanize = (type) => type.toLowerCase().replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
const movementLabel = (type) => MOVEMENT_TYPES.find((movement) => movement.id === type)?.label ?? humanize(String(type ?? 'movement'));
const money = (value) => (value === null || value === undefined ? '—' : formatMoney(value));

export function auditLabel(type) {
    return AUDIT_TYPES.find((entry) => entry.id === type)?.label ?? humanize(type);
}

/** A reprint is journalled as an INVOICE entry (the type list is closed); say so where it is shown. */
export function journalEntryLabel(entry) {
    return entry.event_type === 'INVOICE' && entry.payload_json?.is_reprint ? 'Invoice reprint' : journalLabel(entry.event_type);
}

export function journalLabel(type) {
    return JOURNAL_TYPES.find((entry) => entry.id === type)?.label ?? humanize(type);
}

const PRODUCT_FIELDS = {
    sku: 'SKU',
    barcode: 'barcode',
    name: 'name',
    description: 'description',
    category_id: 'category',
    brand_id: 'brand',
    unit_of_measure: 'unit',
    cost: 'cost',
    selling_price: 'price',
    tax_class: 'tax class',
    track_inventory: 'stock tracking',
    reorder_level: 'reorder level',
    active: 'status',
};

function productValue(field, value) {
    if (value === null || value === undefined || value === '') {
        return 'none';
    }
    if (field === 'cost' || field === 'selling_price') {
        return formatMoney(value);
    }
    if (field === 'active') {
        return value ? 'active' : 'inactive';
    }
    if (field === 'track_inventory') {
        return value ? 'on' : 'off';
    }
    if (field === 'category_id' || field === 'brand_id') {
        return shortId(value);
    }
    return String(value);
}

/** "price ₱55.00 → ₱60.00, status active → inactive" from a product change's before and after. */
function describeProductChange(before = {}, after = {}) {
    const fields = Object.keys(before).filter((field) => field in PRODUCT_FIELDS);
    return fields.map((field) => `${PRODUCT_FIELDS[field]} ${productValue(field, before[field])} → ${productValue(field, after[field])}`).join(', ');
}

/** "Pack Case (24 units, barcode 4800…) added to product RIC-001" or, for a plain alias, "Barcode 4800… added to product RIC-001". */
function describePackaging(snapshot, verb, suffix) {
    const isPack = snapshot.name !== undefined || snapshot.units_per_base !== undefined;
    const what = isPack
        ? `Pack ${snapshot.name ?? '—'}${snapshot.units_per_base ? ` (${formatQuantity(snapshot.units_per_base)} units${snapshot.barcode ? `, barcode ${snapshot.barcode}` : ''})` : snapshot.barcode ? ` (barcode ${snapshot.barcode})` : ''}`
        : `Barcode ${snapshot.barcode ?? '—'}`;

    return `${what} ${verb} product ${snapshot.sku ?? '—'}${suffix && snapshot.barcode ? `; ${suffix}` : ''}`;
}

/** One plain sentence for an audit event, from what the event recorded. Unknown types fall back to their name. */
export function describeAudit(event) {
    const after = event.after_metadata ?? {};
    switch (event.event_type) {
        case 'SALE_FINALIZED':
            return `Sale finalized${after.invoice_number ? `, invoice ${after.invoice_number}` : ''}`;
        case 'INVOICE_REPRINTED':
            return `Invoice ${after.invoice_number ?? '—'} reprinted; no new number was issued`;
        case 'SETTINGS_CHANGED':
            return `Business details changed: ${Object.keys(after).map((field) => field.replaceAll('_', ' ')).join(', ') || 'nothing recorded'}`;
        case 'PRODUCT_CREATED':
            return `Product ${after.sku ?? '—'} created: ${after.name ?? 'unnamed'} at ${money(after.selling_price)}`;
        case 'PRODUCT_UPDATED':
            return `Product ${after.sku ?? '—'} changed: ${describeProductChange(event.before_metadata ?? {}, after) || 'no listed field'}`;
        case 'PRODUCT_IMPORTED':
            return `Product CSV import: ${after.created ?? 0} created, ${after.updated ?? 0} updated, ${after.unchanged ?? 0} unchanged${after.failed ? `, ${after.failed} skipped` : ''}`;
        case 'PRODUCT_BARCODE_ADDED':
            return describePackaging(after, 'added to', 'it can now be scanned to find this product');
        case 'PRODUCT_BARCODE_REMOVED':
            return describePackaging(event.before_metadata ?? {}, 'removed from');
        case 'SALE_VOID_REQUESTED':
            return 'Void requested for a sale';
        case 'SALE_VOIDED':
            return 'Sale voided; every item went back into stock';
        case 'SALE_VOID_REJECTED':
            return 'Void request turned down';
        case 'REFUND_REQUESTED':
            return `Refund of ${money(after.refund_total)} requested`;
        case 'REFUND_CREATED':
            return `Refund of ${money(after.refund_total)} completed`;
        case 'REFUND_REJECTED':
            return 'Refund request turned down';
        case 'STOCK_ADJUSTED':
            return `${movementLabel(after.movement_type)}: ${after.quantity ? formatQuantity(after.quantity) : '—'}${
                after.packaging ? ` (${formatQuantity(after.packaging.packs)} x ${after.packaging.name ?? 'pack'} of ${formatQuantity(after.packaging.units_per_base)})` : ''
            }`;
        case 'STOCK_COUNT_POSTED':
            return `Stock count posted: ${after.lines_counted ?? 0} counted, ${after.lines_adjusted ?? 0} adjusted (${formatQuantity(after.units_found_over ?? '0')} over, ${formatQuantity(after.units_found_short ?? '0')} short)`;
        case 'STOCK_COUNT_CANCELLED':
            return `Stock count cancelled; ${after.lines_discarded ?? 0} counted ${after.lines_discarded === 1 ? 'line was' : 'lines were'} discarded and stock did not change`;
        case 'STOCK_TRANSFERRED':
            return `Stock transferred between locations: ${(after.items ?? []).length} ${(after.items ?? []).length === 1 ? 'product' : 'products'} moved`;
        case 'CASH_IN':
        case 'CASH_OUT':
            return `${event.event_type === 'CASH_IN' ? 'Cash added to' : 'Cash taken from'} the drawer: ${money(after.amount)}`;
        case 'SHIFT_CLOSED':
            return `Shift closed, variance ${money(after.variance)}`;
        case 'X_READING_GENERATED':
            return 'X-Reading generated';
        case 'Z_READING_GENERATED':
            return `Z-Reading #${after.z_counter ?? '?'} generated; the fiscal day closed`;
        default:
            return humanize(event.event_type);
    }
}

/** One plain sentence for a journal entry, from its payload snapshot. */
export function describeJournal(entry) {
    const payload = entry.payload_json ?? {};
    switch (entry.event_type) {
        case 'INVOICE':
            return `${payload.is_reprint ? 'Reprint of invoice' : 'Invoice'} ${payload.invoice_number ?? '—'} · ${money(payload.grand_total)}`;
        case 'VOID':
            return `Void of invoice ${payload.invoice_number ?? '—'} · ${money(payload.grand_total)}`;
        case 'REFUND':
            return `Refund ${money(payload.refund_total)} on invoice ${payload.invoice_number ?? '—'}`;
        case 'STOCK_ADJUSTED':
            return `${movementLabel(payload.movement_type)} ${payload.quantity ? formatQuantity(payload.quantity) : '—'}${payload.sku ? ` · ${payload.sku}` : ''}`;
        case 'CASH_IN':
        case 'CASH_OUT':
            return `${money(payload.amount)}${payload.reason ? ` · ${payload.reason}` : ''}`;
        case 'X_READING':
            return `X-Reading · ${payload.transaction_count ?? 0} sales, expected cash ${money(payload.expected_cash)}`;
        case 'Z_READING':
            return `Z-Reading #${payload.z_counter ?? '?'} · gross sales ${money(payload.gross_sales)}`;
        case 'SHIFT_CLOSED':
            return `Shift closed · variance ${money(payload.variance)}`;
        default:
            return journalLabel(entry.event_type);
    }
}

/** Values that are ids are shown by their random tail with the full id on hover. */
export function KeyValues({ data }) {
    const entries = Object.entries(data ?? {});
    if (entries.length === 0) {
        return <p className="text-xs text-slate-500">Nothing recorded.</p>;
    }
    return (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
            {entries.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="font-mono text-slate-500">{key}</dt>
                    <dd className="min-w-0 break-words text-slate-200">
                        {value === null || value === undefined ? (
                            <span className="text-slate-600">—</span>
                        ) : typeof value === 'object' ? (
                            <pre className="overflow-x-auto whitespace-pre-wrap font-mono text-[11px] text-slate-300">{JSON.stringify(value, null, 2)}</pre>
                        ) : /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(String(value)) ? (
                            <span className="font-mono" title={String(value)}>
                                {shortId(value)}
                            </span>
                        ) : (
                            <span className="font-mono">{String(value)}</span>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function TypeBadge({ label }) {
    return <span className="inline-block whitespace-nowrap rounded border border-slate-700 bg-slate-800/60 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-emerald-300">{label.toUpperCase()}</span>;
}

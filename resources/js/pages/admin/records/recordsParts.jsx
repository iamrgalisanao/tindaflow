import { formatMoney, formatQuantity, shortId } from '../reports/formatters';
import { MOVEMENT_TYPES } from '../inventory/inventoryParts';

/** Audit events the application writes, in the order a person would look for them. */
export const AUDIT_TYPES = [
    { id: 'SALE_FINALIZED', label: 'Sale finalized' },
    { id: 'SALE_VOID_REQUESTED', label: 'Void requested' },
    { id: 'SALE_VOIDED', label: 'Sale voided' },
    { id: 'SALE_VOID_REJECTED', label: 'Void rejected' },
    { id: 'REFUND_REQUESTED', label: 'Refund requested' },
    { id: 'REFUND_CREATED', label: 'Refund completed' },
    { id: 'REFUND_REJECTED', label: 'Refund rejected' },
    { id: 'STOCK_ADJUSTED', label: 'Stock recorded' },
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

export function journalLabel(type) {
    return JOURNAL_TYPES.find((entry) => entry.id === type)?.label ?? humanize(type);
}

/** One plain sentence for an audit event, from what the event recorded. Unknown types fall back to their name. */
export function describeAudit(event) {
    const after = event.after_metadata ?? {};
    switch (event.event_type) {
        case 'SALE_FINALIZED':
            return `Sale finalized${after.invoice_number ? `, invoice ${after.invoice_number}` : ''}`;
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
            return `${movementLabel(after.movement_type)}: ${after.quantity ? formatQuantity(after.quantity) : '—'}`;
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
            return `Invoice ${payload.invoice_number ?? '—'} · ${money(payload.grand_total)}`;
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

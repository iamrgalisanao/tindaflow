import { useEffect, useState } from 'react';
import InvoicePanel from '../admin/sales/InvoicePanel';
import RefundPanel from '../admin/sales/RefundPanel';
import VoidSalePanel from '../admin/sales/VoidSalePanel';
import { failureMessage, request } from '../admin/catalog/catalogApi';
import { formatMoney } from '../admin/reports/formatters';

/**
 * Transaction lookup inside POS mode (docs/06-ui/sitemap.md /pos/lookup).
 *
 * The sitemap's Stage 7 validation pass settled that this belongs at the till rather than only in the
 * back office, against UTAK/StoreHub reference behaviour: a cashier reprinting a receipt should not
 * have to leave the register. Until now the POS header sent them to /admin/sales, which drops them
 * into the back-office shell in a different visual mode.
 *
 * Scope is what `saleList`/`saleGet` actually expose. Two columns from the Stitch mockup are absent
 * on purpose: item count and tender method are not on SaleSummary, and fetching every row's detail to
 * show them would be a request per row -- both appear in the inspector, where the detail call already
 * provides them. The mockup's HOLD status is not a real sale status either (the enum is COMPLETED,
 * VOIDED, PARTIALLY_REFUNDED, REFUNDED), and its hardware/e-receipt/CAS-log actions have no endpoints.
 *
 * Printing is delegated to the admin InvoicePanel rather than reimplemented: reprint semantics are
 * subtle (one Idempotency-Key per attempt, every copy separately audited and visibly marked) and a
 * third copy of that logic would be a third chance to get it wrong.
 */

const SEARCH_DEBOUNCE_MS = 250;

const STATUSES = [
    { id: '', label: 'All' },
    { id: 'COMPLETED', label: 'Completed' },
    { id: 'VOIDED', label: 'Voided' },
    { id: 'PARTIALLY_REFUNDED', label: 'Part. refunded' },
    { id: 'REFUNDED', label: 'Refunded' },
];

const STATUS_TONE = {
    COMPLETED: 'border-emerald-700/70 text-emerald-400',
    VOIDED: 'border-rose-700/70 text-rose-400',
    REFUNDED: 'border-amber-700/70 text-amber-400',
    PARTIALLY_REFUNDED: 'border-amber-700/70 text-amber-400',
};

function today() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function timeOf(iso) {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? String(iso) : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function StatusBadge({ status }) {
    return (
        <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${STATUS_TONE[status] ?? 'border-slate-600 text-slate-300'}`}>
            {status.replaceAll('_', ' ')}
        </span>
    );
}

export default function PosLookupPanel({ canSeeAllSales, capabilities }) {
    const can = (capability) => capabilities.includes(capability);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [todayOnly, setTodayOnly] = useState(true);

    const [rows, setRows] = useState(null);
    const [listFailure, setListFailure] = useState(null);
    const [loading, setLoading] = useState(true);

    const [selectedId, setSelectedId] = useState(null);
    const [detail, setDetail] = useState(null);
    const [detailFailure, setDetailFailure] = useState(null);
    const [invoice, setInvoice] = useState(null); // { id, number } while the receipt panel is open
    const [panel, setPanel] = useState(null); // 'void' | 'refund'
    const [notice, setNotice] = useState(null);

    // Bumped by the Search button so pressing it re-runs the same query straight away.
    const [reloadTick, setReloadTick] = useState(0);

    // Re-read the sale and the journal after a reversal: the status, and the void/refund blocks under
    // it, all change. Sits below reloadTick because it drives it.
    const reloadAfterReversal = (message) => {
        setPanel(null);
        setNotice(message);
        setReloadTick((tick) => tick + 1);
        // Re-read the detail directly: selectedId has not changed, so the effect that loads it would
        // not re-run on its own.
        request(`/api/v1/sales/${selectedId}`).then((response) => response.ok && setDetail(response.body));
    };

    // A request per keystroke would let a slow answer for "T-0" land after the answer for "T-01M3" and
    // replace it, so typing waits briefly and any request that has been superseded is discarded.
    useEffect(() => {
        let cancelled = false;
        const term = search.trim();

        const run = async () => {
            setLoading(true);
            setListFailure(null);
            const params = new URLSearchParams({ per_page: '25', sort: '-sold_at' });
            if (term !== '') {
                // transaction_number is "T-" + ULID (Stage 6C ruling); anything else is treated as an
                // invoice number. Two separate filters exist server-side, so the box has to pick one.
                params.set(/^t-/i.test(term) ? 'transaction_number' : 'invoice_number', term);
            }
            if (status !== '') {
                params.set('status', status);
            }
            if (todayOnly) {
                params.set('from', today());
                params.set('to', today());
            }
            const response = await request(`/api/v1/sales?${params.toString()}`);
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setRows(response.body.data);
            } else {
                setRows(null);
                setListFailure(response);
            }
            setLoading(false);
        };

        const timer = setTimeout(run, term === '' ? 0 : SEARCH_DEBOUNCE_MS);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [search, status, todayOnly, reloadTick]);

    useEffect(() => {
        if (selectedId === null) {
            setDetail(null);
            return undefined;
        }
        let cancelled = false;
        setDetail(null);
        setDetailFailure(null);
        request(`/api/v1/sales/${selectedId}`).then((response) => {
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setDetail(response.body);
            } else {
                setDetailFailure(response);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [selectedId]);

    return (
        <div className="mx-auto grid w-full max-w-[1600px] grid-cols-1 gap-4 p-4 lg:h-[calc(100dvh-4.5rem)] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-[minmax(0,1fr)]">
            {/* ---------------------------------------------------------------- the journal */}
            <section className="flex min-h-0 flex-col rounded-lg border border-slate-700 bg-slate-900">
                <div className="space-y-3 border-b border-slate-800 p-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setReloadTick((tick) => tick + 1);
                        }}
                        className="flex gap-2"
                    >
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Transaction or invoice number"
                            aria-label="Search by transaction or invoice number"
                            className="min-h-12 w-full rounded border border-slate-700 bg-slate-950 px-3 font-mono text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                        />
                        <button type="submit" className="min-h-12 shrink-0 rounded bg-emerald-500 px-4 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400">
                            Search
                        </button>
                    </form>

                    <div className="flex flex-wrap gap-1">
                        {STATUSES.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                onClick={() => setStatus(option.id)}
                                aria-pressed={status === option.id}
                                className={`min-h-11 rounded border px-3 font-mono text-[11px] uppercase tracking-wider lg:min-h-8 ${
                                    status === option.id ? 'border-emerald-500 bg-emerald-950/60 text-emerald-300' : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setTodayOnly((prior) => !prior)}
                            aria-pressed={todayOnly}
                            className={`min-h-11 rounded border px-3 font-mono text-[11px] uppercase tracking-wider lg:min-h-8 ${
                                todayOnly ? 'border-emerald-500 bg-emerald-950/60 text-emerald-300' : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                            }`}
                        >
                            Today
                        </button>
                    </div>

                    {!canSeeAllSales && (
                        <p className="text-[11px] text-slate-500">You see your own transactions. A manager can look up everyone&apos;s.</p>
                    )}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {listFailure && (
                        <p role="alert" className="m-3 rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-300">
                            {failureMessage(listFailure, 'The transactions could not be loaded.')}
                        </p>
                    )}

                    {loading && rows === null && !listFailure && (
                        <div className="space-y-2 p-3" aria-busy="true" aria-label="Loading transactions">
                            {[0, 1, 2, 3, 4].map((n) => (
                                <div key={n} className="h-12 animate-pulse rounded bg-slate-950" />
                            ))}
                        </div>
                    )}

                    {rows && rows.length === 0 && (
                        <p className="p-6 text-center text-sm text-slate-400">
                            {search.trim() !== '' ? 'No transaction matches that number.' : todayOnly ? 'Nothing has been sold today yet.' : 'No transactions found.'}
                        </p>
                    )}

                    {rows && rows.length > 0 && (
                        <ul className={loading ? 'opacity-60 transition-opacity' : ''}>
                            {rows.map((sale) => {
                                const selected = sale.id === selectedId;
                                return (
                                    <li key={sale.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedId(sale.id)}
                                            aria-current={selected ? 'true' : undefined}
                                            className={`flex w-full items-center gap-3 border-b border-slate-800/70 px-3 py-2 text-left hover:bg-slate-800 ${
                                                selected ? 'border-l-2 border-l-emerald-500 bg-emerald-950/20' : 'border-l-2 border-l-transparent'
                                            }`}
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-mono text-[12px] text-slate-100">{sale.transaction_number}</span>
                                                <span className="block truncate font-mono text-[11px] text-slate-500">
                                                    {sale.invoice_number ?? 'no invoice'} · {timeOf(sale.sold_at)}
                                                </span>
                                            </span>
                                            <StatusBadge status={sale.status} />
                                            <span className="shrink-0 font-mono text-sm tabular-nums text-slate-100">{formatMoney(sale.grand_total)}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </section>

            {/* ---------------------------------------------------------------- the inspector */}
            <section className="flex min-h-0 flex-col overflow-y-auto rounded-lg border border-slate-700 bg-slate-900 p-4">
                {notice && (
                    <p role="status" className="mb-3 rounded border border-emerald-800/60 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-300">
                        {notice}
                    </p>
                )}

                {selectedId === null && (
                    <p className="m-auto max-w-sm text-center text-sm text-slate-500">
                        Pick a transaction to see what was sold, how it was paid, and to print a copy of its receipt.
                    </p>
                )}

                {detailFailure && (
                    <p role="alert" className="rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-300">
                        {failureMessage(detailFailure, 'That transaction could not be opened.')}
                    </p>
                )}

                {selectedId !== null && detail === null && !detailFailure && <p className="m-auto text-sm text-slate-500">Loading…</p>}

                {detail && (
                    <>
                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-800 pb-3">
                            <div className="min-w-0">
                                <p className="font-mono text-sm text-slate-100">{detail.transaction_number}</p>
                                <p className="font-mono text-[11px] text-slate-500">
                                    {detail.invoice_number ?? 'no invoice number'} · {new Date(detail.sold_at).toLocaleString()}
                                </p>
                            </div>
                            <StatusBadge status={detail.status} />
                        </div>

                        <table className="mt-3 w-full text-left text-sm">
                            <tbody className="divide-y divide-slate-800/70">
                                {detail.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-1.5">
                                            <span className="block text-slate-100">{item.product_name_snapshot}</span>
                                            <span className="block font-mono text-[11px] text-slate-500">
                                                {item.quantity} × {formatMoney(item.unit_price_snapshot)}
                                                {Number(item.line_discount_amount) > 0 && <span className="text-emerald-400"> · less {formatMoney(item.line_discount_amount)}</span>}
                                            </span>
                                        </td>
                                        <td className="py-1.5 text-right font-mono tabular-nums text-slate-100">{formatMoney(item.net_line_amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <dl className="mt-3 space-y-1 border-t border-slate-800 pt-3 text-sm">
                            {[
                                ['Subtotal', detail.subtotal],
                                ['Discounts', detail.discount_total],
                                ['VAT', detail.tax_summary.vat_amount],
                            ].map(([label, value]) => (
                                <div key={label} className="flex justify-between text-slate-400">
                                    <dt>{label}</dt>
                                    <dd className="font-mono tabular-nums text-slate-200">{formatMoney(value)}</dd>
                                </div>
                            ))}
                            <div className="flex justify-between border-t border-slate-800 pt-1 text-base font-semibold text-slate-100">
                                <dt>Total</dt>
                                <dd className="font-mono tabular-nums">{formatMoney(detail.grand_total)}</dd>
                            </div>
                            {detail.payments.map((payment) => (
                                <div key={payment.id} className="flex justify-between text-slate-400">
                                    <dt>{payment.method}</dt>
                                    <dd className="font-mono tabular-nums text-slate-200">{formatMoney(payment.amount)}</dd>
                                </div>
                            ))}
                            <div className="flex justify-between text-slate-400">
                                <dt>Change</dt>
                                <dd className="font-mono tabular-nums text-slate-200">{formatMoney(detail.change)}</dd>
                            </div>
                        </dl>

                        {detail.void && (
                            <p className="mt-3 rounded border border-rose-800/60 bg-rose-950/30 px-3 py-2 text-xs text-rose-300">
                                Void {detail.void.status.toLowerCase()} — {detail.void.reason ?? 'no reason recorded'}
                            </p>
                        )}
                        {detail.refunds.length > 0 && (
                            <p className="mt-2 rounded border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-xs text-amber-300">
                                {detail.refunds.length} refund{detail.refunds.length === 1 ? '' : 's'} recorded against this sale.
                            </p>
                        )}

                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={!detail.invoice}
                                onClick={() => setInvoice({ id: detail.invoice.id, number: detail.invoice_number })}
                                className="min-h-12 rounded bg-emerald-500 px-5 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500"
                            >
                                Receipt
                            </button>
                            {detail.status === 'COMPLETED' && can('SALE_VOID') && (
                                <button
                                    type="button"
                                    onClick={() => setPanel('void')}
                                    className="min-h-12 rounded border border-rose-800/60 px-4 text-sm font-bold uppercase tracking-wider text-rose-400 hover:bg-rose-600 hover:text-white"
                                >
                                    {can('SALE_VOID_APPROVE') ? 'Void sale' : 'Request void'}
                                </button>
                            )}
                            {['COMPLETED', 'PARTIALLY_REFUNDED'].includes(detail.status) && can('SALE_REFUND') && (
                                <button
                                    type="button"
                                    onClick={() => setPanel('refund')}
                                    className="min-h-12 rounded border border-amber-800/60 px-4 text-sm font-bold uppercase tracking-wider text-amber-400 hover:bg-amber-600 hover:text-slate-950"
                                >
                                    {can('SALE_REFUND_APPROVE') ? 'Refund' : 'Request refund'}
                                </button>
                            )}
                        </div>
                        <p className="mt-2 text-[11px] text-slate-500">
                            Printing from here records a marked copy; the unmarked original is only ever printed once, at the sale itself.
                            {!can('SALE_VOID_APPROVE') && (detail.status === 'COMPLETED' || detail.status === 'PARTIALLY_REFUNDED')
                                ? ' A void or refund you raise here is a request — a manager decides it under Approvals.'
                                : ''}
                        </p>
                    </>
                )}
            </section>

            {panel === 'void' && detail && (
                <VoidSalePanel
                    sale={detail}
                    executesImmediately={can('SALE_VOID_APPROVE')}
                    onDone={(result) => reloadAfterReversal(result.status === 'VOIDED' ? 'Sale voided.' : 'Void requested. A manager decides it under Approvals.')}
                    onClose={() => setPanel(null)}
                    onUnauthorized={() => setPanel(null)}
                />
            )}

            {panel === 'refund' && detail && (
                <RefundPanel
                    sale={detail}
                    executesImmediately={can('SALE_REFUND_APPROVE')}
                    onDone={(result) => reloadAfterReversal(result.status === 'COMPLETED' ? 'Refund completed.' : 'Refund requested. A manager decides it under Approvals.')}
                    onClose={() => setPanel(null)}
                    onUnauthorized={() => setPanel(null)}
                />
            )}

            {invoice && (
                <InvoicePanel
                    invoiceId={invoice.id}
                    invoiceNumber={invoice.number}
                    canPrint
                    onClose={() => setInvoice(null)}
                    onUnauthorized={() => setInvoice(null)}
                />
            )}
        </div>
    );
}

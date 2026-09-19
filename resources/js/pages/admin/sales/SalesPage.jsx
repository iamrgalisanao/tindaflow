import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime, formatMoney, shortId } from '../reports/formatters';
import { PAYMENT_METHODS, StatusBadge } from './salesParts';

const PER_PAGE = 25;
const STATUSES = ['COMPLETED', 'VOIDED', 'PARTIALLY_REFUNDED', 'REFUNDED'];
const SORTS = [
    { id: '-sold_at', label: 'Newest first' },
    { id: 'sold_at', label: 'Oldest first' },
    { id: '-grand_total', label: 'Highest total' },
    { id: 'grand_total', label: 'Lowest total' },
];
const EMPTY = { status: '', payment_method: '', from: '', to: '', invoice_number: '', transaction_number: '', sort: '-sold_at' };

const fieldClass = 'min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0';

function Field({ id, label, children }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs text-slate-500">
                {label}
            </label>
            {children}
        </div>
    );
}

/**
 * /admin/sales -- saleList: every sale of the store, newest first, with the contract's filters. Reading
 * needs only a session; voids and refunds are started from a sale's own page.
 */
export default function SalesPage() {
    const { setUser } = useAuth();
    const [filters, setFilters] = useState(EMPTY);
    const [draftInvoice, setDraftInvoice] = useState('');
    const [draftTransaction, setDraftTransaction] = useState('');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const seq = useRef(0);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
        setLoading(true);
        setFailure(null);
        request(`/api/v1/sales?${params.toString()}`).then((response) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setResult(response.body);
            } else {
                setResult(null);
                setFailure(response);
            }
        });
    }, [filters, page, reloadKey]);

    function change(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
    }

    function commitText(event) {
        if (event.type === 'keydown' && event.key !== 'Enter') {
            return;
        }
        change({ invoice_number: draftInvoice.trim(), transaction_number: draftTransaction.trim() });
    }

    function clear() {
        setFilters({ ...EMPTY, sort: filters.sort });
        setDraftInvoice('');
        setDraftTransaction('');
        setPage(1);
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const sales = result?.data ?? [];
    const filtered = Object.entries(filters).some(([key, value]) => key !== 'sort' && value !== '');

    return (
        <AdminLayout title="Sales history" requiredCapability="SALE_VOID" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Sales history</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">Every sale in your store. Open one to see its receipt detail, or to void or refund it.</p>
            </div>

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field id="sales_invoice" label="Invoice no.">
                    <input
                        id="sales_invoice"
                        type="search"
                        inputMode="numeric"
                        placeholder="000123"
                        value={draftInvoice}
                        onChange={(event) => setDraftInvoice(event.target.value)}
                        onBlur={commitText}
                        onKeyDown={commitText}
                        className={`${fieldClass} font-mono`}
                    />
                </Field>
                <Field id="sales_transaction" label="Transaction no.">
                    <input
                        id="sales_transaction"
                        type="search"
                        placeholder="T-01J…"
                        value={draftTransaction}
                        onChange={(event) => setDraftTransaction(event.target.value)}
                        onBlur={commitText}
                        onKeyDown={commitText}
                        className={`${fieldClass} font-mono`}
                    />
                </Field>
                <Field id="sales_status" label="Status">
                    <select id="sales_status" value={filters.status} onChange={(event) => change({ status: event.target.value })} className={fieldClass}>
                        <option value="">All statuses</option>
                        {STATUSES.map((status) => (
                            <option key={status} value={status}>
                                {status.replaceAll('_', ' ')}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field id="sales_payment" label="Paid with">
                    <select id="sales_payment" value={filters.payment_method} onChange={(event) => change({ payment_method: event.target.value })} className={fieldClass}>
                        <option value="">Any method</option>
                        {PAYMENT_METHODS.map((method) => (
                            <option key={method} value={method}>
                                {method}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field id="sales_from" label="From">
                    <input id="sales_from" type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </Field>
                <Field id="sales_to" label="To">
                    <input id="sales_to" type="date" value={filters.to} onChange={(event) => change({ to: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </Field>
                <Field id="sales_sort" label="Order">
                    <select id="sales_sort" value={filters.sort} onChange={(event) => change({ sort: event.target.value })} className={fieldClass}>
                        {SORTS.map((sort) => (
                            <option key={sort.id} value={sort.id}>
                                {sort.label}
                            </option>
                        ))}
                    </select>
                </Field>
                {filtered && (
                    <div className="flex items-end">
                        <button type="button" onClick={clear} className="min-h-11 text-xs text-emerald-400 underline lg:min-h-0">
                            Clear filters
                        </button>
                    </div>
                )}
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The sales could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading sales">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && sales.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'No sales match these filters.' : 'No sales have been rung up yet.'}</p>
                    {filtered && (
                        <button type="button" onClick={clear} className="mt-2 text-xs text-emerald-400 underline">
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {result && sales.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Invoice', 'Transaction', 'Sold', 'Cashier', 'Total', 'Status'].map((heading) => (
                                        <th key={heading} className={`border-b border-slate-800 px-3 py-2 font-mono ${heading === 'Total' ? 'text-right' : ''}`}>
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {sales.map((sale) => (
                                    <tr key={sale.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="px-3 py-2 font-mono">
                                            <Link to={`/admin/sales/${sale.id}`} className="text-emerald-400 hover:underline">
                                                {sale.invoice_number ?? '—'}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400" title={sale.transaction_number}>
                                            {sale.transaction_number.slice(0, 14)}
                                            {sale.transaction_number.length > 14 ? '…' : ''}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 font-mono text-[12px] text-slate-400">{formatDateTime(sale.sold_at)}</td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-500" title={sale.cashier_id}>
                                            {shortId(sale.cashier_id)}
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-100">{formatMoney(sale.grand_total)}</td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={sale.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Sales">
                        {sales.map((sale) => (
                            <li key={sale.id}>
                                <Link to={`/admin/sales/${sale.id}`} className="block rounded-lg border border-slate-800 bg-slate-900 p-3 hover:bg-slate-800/60">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-mono text-sm text-emerald-400">Invoice {sale.invoice_number ?? '—'}</span>
                                        <StatusBadge status={sale.status} />
                                    </div>
                                    <div className="mt-1 flex items-center justify-between text-sm">
                                        <span className="font-mono text-[11px] text-slate-500">{formatDateTime(sale.sold_at)}</span>
                                        <span className="font-mono tabular-nums text-slate-100">{formatMoney(sale.grand_total)}</span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}
        </AdminLayout>
    );
}

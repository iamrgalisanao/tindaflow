import { useState } from 'react';
import { Link } from 'react-router-dom';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { failureMessage } from '../catalog/catalogApi';
import { formatDateTime, shortId } from '../reports/formatters';
import { DayStatusBadge, Money, Variance, fieldClass, useLoad, useSignIn } from './historyParts';

const PER_PAGE = 25;
const EMPTY = { from: '', to: '' };

/**
 * /admin/shifts -- shiftList: every cashier shift of the store, newest first, with what the drawer should
 * hold, what was declared and the difference. Reading needs only a session.
 */
export default function ShiftsPage() {
    const signIn = useSignIn();
    const [filters, setFilters] = useState(EMPTY);
    const [page, setPage] = useState(1);
    const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
    Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
    const { data: result, failure, loading, reload } = useLoad(`/api/v1/shifts?${params.toString()}`);

    function change(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
    }

    const shifts = result?.data ?? [];
    const filtered = filters.from !== '' || filters.to !== '';

    return (
        <AdminLayout title="Shifts" requiredCapability="REPORT_VIEW" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Shifts</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Each cashier&apos;s time at a till: the cash they started with, what the drawer should hold at close, what they counted, and the difference.
                </p>
            </div>

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-3">
                <div>
                    <label htmlFor="shift_from" className="mb-1 block text-xs text-slate-500">
                        Opened from
                    </label>
                    <input id="shift_from" type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
                <div>
                    <label htmlFor="shift_to" className="mb-1 block text-xs text-slate-500">
                        Opened to
                    </label>
                    <input id="shift_to" type="date" value={filters.to} onChange={(event) => change({ to: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
                {filtered && (
                    <div className="flex items-end">
                        <button type="button" onClick={() => change(EMPTY)} className="min-h-11 text-xs text-emerald-400 underline lg:min-h-0">
                            Clear dates
                        </button>
                    </div>
                )}
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The shifts could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading shifts">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && shifts.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'No shifts were opened in that period.' : 'No shifts yet. They appear here once a cashier opens one at the POS.'}</p>
                </div>
            )}

            {result && shifts.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Opened', 'Closed', 'Cashier', 'Terminal', 'Status', 'Opening', 'Expected', 'Declared', 'Variance'].map((heading) => (
                                        <th key={heading} className={`border-b border-slate-800 px-3 py-2 font-mono ${['Opening', 'Expected', 'Declared', 'Variance'].includes(heading) ? 'text-right' : ''}`}>
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {shifts.map((shift) => (
                                    <tr key={shift.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="px-3 py-2 font-mono text-[12px]">
                                            <Link to={`/admin/shifts/${shift.id}`} className="text-emerald-400 hover:underline">
                                                {formatDateTime(shift.opened_at)}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400">{shift.closed_at ? formatDateTime(shift.closed_at) : '—'}</td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400" title={shift.cashier_id}>
                                            {shortId(shift.cashier_id)}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400" title={shift.terminal_id}>
                                            {shortId(shift.terminal_id)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <DayStatusBadge status={shift.status} />
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-300">
                                            <Money value={shift.opening_cash} />
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-300">
                                            <Money value={shift.expected_cash} />
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-300">
                                            <Money value={shift.declared_cash} />
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums">
                                            <Variance value={shift.variance} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Shifts">
                        {shifts.map((shift) => (
                            <li key={shift.id} className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                                <div className="flex items-start justify-between gap-2">
                                    <Link to={`/admin/shifts/${shift.id}`} className="font-mono text-[13px] text-emerald-400 hover:underline">
                                        {formatDateTime(shift.opened_at)}
                                    </Link>
                                    <DayStatusBadge status={shift.status} />
                                </div>
                                <p className="mt-1 font-mono text-[11px] text-slate-500">
                                    cashier {shortId(shift.cashier_id)} · terminal {shortId(shift.terminal_id)}
                                </p>
                                <dl className="mt-2 space-y-1 text-sm">
                                    <div className="flex justify-between text-slate-400">
                                        <dt>Expected</dt>
                                        <dd className="font-mono tabular-nums text-slate-200">
                                            <Money value={shift.expected_cash} />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between text-slate-400">
                                        <dt>Declared</dt>
                                        <dd className="font-mono tabular-nums text-slate-200">
                                            <Money value={shift.declared_cash} />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between border-t border-slate-800 pt-1 text-slate-400">
                                        <dt>Variance</dt>
                                        <dd className="font-mono tabular-nums">
                                            <Variance value={shift.variance} />
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}
        </AdminLayout>
    );
}

import { useState } from 'react';
import { Link } from 'react-router-dom';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { failureMessage } from '../catalog/catalogApi';
import { formatDateTime, shortId } from '../reports/formatters';
import { DayStatusBadge, fieldClass, useLoad, useSignIn } from './historyParts';

const PER_PAGE = 25;
const EMPTY = { from: '', to: '' };

/**
 * /admin/fiscal-days -- fiscalDayList: each terminal's business days, most recent first. A closed day has a
 * Z-reading. Reading needs only a session.
 */
export default function FiscalDaysPage() {
    const signIn = useSignIn();
    const [filters, setFilters] = useState(EMPTY);
    const [page, setPage] = useState(1);
    const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
    Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
    const { data: result, failure, loading, reload } = useLoad(`/api/v1/fiscal-days?${params.toString()}`);

    function change(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
    }

    const days = result?.data ?? [];
    const filtered = filters.from !== '' || filters.to !== '';

    return (
        <AdminLayout title="Fiscal days" requiredCapability="REPORT_VIEW" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Fiscal days</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    A terminal&apos;s business day, from the first shift that opened it to the end-of-day close. Closing a day produces its Z-reading, which you can open here.
                </p>
            </div>

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-3">
                <div>
                    <label htmlFor="day_from" className="mb-1 block text-xs text-slate-500">
                        Business date from
                    </label>
                    <input id="day_from" type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
                <div>
                    <label htmlFor="day_to" className="mb-1 block text-xs text-slate-500">
                        Business date to
                    </label>
                    <input id="day_to" type="date" value={filters.to} onChange={(event) => change({ to: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
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
                    message={failureMessage(failure, 'The fiscal days could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading fiscal days">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && days.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'No fiscal days in that period.' : 'No fiscal days yet. The first one opens with the first shift at a terminal.'}</p>
                </div>
            )}

            {result && days.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <ul className="divide-y divide-slate-800/70 overflow-hidden rounded-lg border border-slate-800 bg-slate-900" aria-label="Fiscal days">
                        {days.map((day) => (
                            <li key={day.id} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3">
                                <Link to={`/admin/fiscal-days/${day.id}`} className="w-28 shrink-0 font-mono text-sm text-emerald-400 hover:underline">
                                    {day.business_date}
                                </Link>
                                <span className="w-16 shrink-0">
                                    <DayStatusBadge status={day.status} />
                                </span>
                                <span className="font-mono text-[12px] text-slate-400" title={day.terminal_id}>
                                    terminal {shortId(day.terminal_id)}
                                </span>
                                <span className="font-mono text-[12px] text-slate-500">
                                    opened {formatDateTime(day.opened_at)}
                                    {day.closed_at ? ` · closed ${formatDateTime(day.closed_at)}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}
        </AdminLayout>
    );
}

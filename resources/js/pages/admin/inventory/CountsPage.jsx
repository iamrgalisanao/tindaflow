import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import SlideOver from '../SlideOver';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime } from '../reports/formatters';
import { useInventoryLookups } from './inventoryParts';
import { CountStatusBadge } from './stockDocsParts';

const PER_PAGE = 25;
const STATUS_TABS = [
    { id: '', label: 'All' },
    { id: 'OPEN', label: 'In progress' },
    { id: 'POSTED', label: 'Posted' },
    { id: 'CANCELLED', label: 'Cancelled' },
];

const inputClass = 'min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0';

/**
 * Start a count of one location. A location has at most one count in progress, so if there already is one the
 * server says which, and the panel offers to open it instead.
 */
function StartCountPanel({ locations, onStarted, onClose, onUnauthorized }) {
    const defaultLocation = locations.find((location) => location.is_default) ?? locations[0];
    const [locationId, setLocationId] = useState(defaultLocation?.id ?? '');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [existingId, setExistingId] = useState(null);

    async function submit(event) {
        event.preventDefault();
        setSaving(true);
        setError(null);
        setExistingId(null);
        const body = { location_id: locationId };
        if (note.trim() !== '') {
            body.note = note.trim();
        }
        const result = await request('/api/v1/inventory/counts', { method: 'POST', body });
        setSaving(false);
        if (result.ok) {
            onStarted(result.body);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        if (result.body?.error?.code === 'STOCK_COUNT_ALREADY_OPEN') {
            setExistingId(result.body.error.details?.stock_count_id ?? null);
            setError('This location already has a count in progress. Add to that one instead of starting another.');
            return;
        }
        setError(failureMessage(result, 'The count could not be started.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="start_count_form"
                disabled={saving || locationId === ''}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Starting…' : 'Start count'}
            </button>
        </div>
    );

    return (
        <SlideOver titleId="start_count_title" title="Start a stock count" onClose={onClose} footer={footer}>
            <form id="start_count_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {error && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {error}{' '}
                        {existingId && (
                            <Link to={`/admin/inventory/counts/${existingId}`} className="text-emerald-400 underline">
                                Open it
                            </Link>
                        )}
                    </p>
                )}
                <p className="text-sm text-slate-400">
                    Count the products on one shelf or in one room, then post the count to correct the system. Products you do not count are left exactly as they are.
                </p>
                <div>
                    <label htmlFor="count_location" className="mb-1 block text-xs text-slate-400">
                        Location <span className="text-emerald-400">*</span>
                    </label>
                    <select id="count_location" value={locationId} onChange={(event) => setLocationId(event.target.value)} className={inputClass}>
                        {locations.map((location) => (
                            <option key={location.id} value={location.id}>
                                {location.name}
                                {location.is_default ? ' (default)' : ''}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="count_note" className="mb-1 block text-xs text-slate-400">
                        Note
                    </label>
                    <input
                        id="count_note"
                        type="text"
                        autoComplete="off"
                        maxLength={255}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        className={inputClass}
                    />
                    <p className="mt-1 text-[11px] text-slate-500">Optional. For example “Aisle 2” or “Month-end count”.</p>
                </div>
            </form>
        </SlideOver>
    );
}

/**
 * /admin/inventory/counts -- stockCountList and stockCountCreate (STOCK_ADJUST, session only). Counts are
 * worked on their own page; posting one needs an enrolled terminal (see CountDetailPage).
 */
export default function CountsPage() {
    const { setUser } = useAuth();
    const navigate = useNavigate();
    const lookups = useInventoryLookups();
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [starting, setStarting] = useState(false);
    const seq = useRef(0);
    const closePanel = useCallback(() => setStarting(false), []);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (status) {
            params.set('status', status);
        }
        setLoading(true);
        setFailure(null);
        request(`/api/v1/inventory/counts?${params.toString()}`).then((response) => {
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
    }, [status, page, reloadKey]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const rows = result?.data ?? [];
    const open = rows.find((row) => row.status === 'OPEN');

    return (
        <AdminLayout title="Stock counts" requiredCapability="STOCK_ADJUST" wide>
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Stock counts</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        Count what is really on the shelf and post the difference. Sales made while you are counting are kept, and products you do not count are left alone.
                    </p>
                </div>
                <button
                    type="button"
                    disabled={lookups.loading || lookups.locations.length === 0}
                    onClick={() => setStarting(true)}
                    className="min-h-11 shrink-0 self-start whitespace-nowrap rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                >
                    Start a count
                </button>
            </div>

            {lookups.failed && !lookups.loading && <ErrorAlert message="Locations could not be loaded, so a count cannot be started yet." onRetry={lookups.retry} />}

            <div role="group" aria-label="Status" className="mb-3 flex overflow-hidden rounded-md border border-slate-700 sm:inline-flex">
                {STATUS_TABS.map((tab) => (
                    <button
                        key={tab.id || 'all'}
                        type="button"
                        aria-pressed={status === tab.id}
                        onClick={() => {
                            setStatus(tab.id);
                            setPage(1);
                        }}
                        className={`min-h-11 flex-1 px-3 py-1.5 text-xs sm:flex-none lg:min-h-0 ${status === tab.id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'}`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The counts could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading counts">
                    {[0, 1, 2].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{status ? 'No counts with this status.' : 'No stock count has been started yet.'}</p>
                    {!status && <p className="mt-1 text-xs text-slate-500">Start one to check the shelf against what the system shows.</p>}
                </div>
            )}

            {result && rows.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    {open && status !== 'OPEN' && (
                        <p className="mb-2 text-xs text-slate-500">
                            A count is in progress at {open.location_name}.{' '}
                            <Link to={`/admin/inventory/counts/${open.id}`} className="text-emerald-400 underline">
                                Continue it
                            </Link>
                        </p>
                    )}
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Started', 'Location', 'Status', 'Products counted', 'Note', ''].map((heading) => (
                                        <th key={heading || 'open'} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {rows.map((row) => (
                                    <tr key={row.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400">{formatDateTime(row.created_at)}</td>
                                        <td className="px-3 py-2 text-slate-100">{row.location_name}</td>
                                        <td className="px-3 py-2">
                                            <CountStatusBadge status={row.status} />
                                        </td>
                                        <td className="px-3 py-2 font-mono tabular-nums text-slate-300">{row.lines_count}</td>
                                        <td className="max-w-xs truncate px-3 py-2 text-slate-400">{row.note ?? '—'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Link to={`/admin/inventory/counts/${row.id}`} className="whitespace-nowrap text-xs text-emerald-400 hover:underline">
                                                {row.status === 'OPEN' ? 'Continue' : 'View'}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Stock counts">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <Link to={`/admin/inventory/counts/${row.id}`} className="block rounded-lg border border-slate-800 bg-slate-900 p-3 hover:bg-slate-800/60">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm text-slate-100">{row.location_name}</span>
                                        <CountStatusBadge status={row.status} />
                                    </div>
                                    <p className="mt-1 font-mono text-[11px] text-slate-500">
                                        {formatDateTime(row.created_at)} &middot; {row.lines_count} counted
                                    </p>
                                    {row.note && <p className="mt-1 break-words text-xs text-slate-400">{row.note}</p>}
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {starting && (
                <StartCountPanel
                    locations={lookups.locations}
                    onClose={closePanel}
                    onStarted={(count) => navigate(`/admin/inventory/counts/${count.id}`)}
                    onUnauthorized={signIn}
                />
            )}
        </AdminLayout>
    );
}

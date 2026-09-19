import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime, shortId } from '../reports/formatters';
import { JOURNAL_TYPES, KeyValues, TypeBadge, describeJournal, journalLabel } from './recordsParts';

const PER_PAGE = 25;
const EMPTY = { event_type: '', from: '', to: '' };
const fieldClass = 'min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0';

function Details({ entry }) {
    return (
        <div className="space-y-3 border-t border-slate-800 bg-slate-950/60 px-4 py-3">
            <KeyValues
                data={{
                    id: entry.id,
                    event_type: entry.event_type,
                    source_type: entry.source_type,
                    source_id: entry.source_id,
                    terminal_id: entry.terminal_id,
                    audit_event_id: entry.audit_event_id,
                    occurred_at: entry.occurred_at,
                }}
            />
            <div>
                <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-slate-500">Recorded snapshot</p>
                <KeyValues data={entry.payload_json} />
            </div>
        </div>
    );
}

/**
 * /admin/journal -- journalEntryList (JOURNAL_VIEW). The electronic journal is the fiscal record of what
 * happened at the till: invoices, voids, refunds, readings, cash movements and stock changes, each a
 * snapshot taken at the moment it happened. It is a projection of the real records, so it cannot be
 * edited. "Export CSV" downloads every entry matching the current filters (not just this page) in the
 * pinned column layout of csv-export-contract.md.
 */
export default function JournalPage() {
    const { setUser } = useAuth();
    const [filters, setFilters] = useState(EMPTY);
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [openId, setOpenId] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [exportError, setExportError] = useState(null);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const queryFor = useCallback(
        (extra = {}) => {
            const params = new URLSearchParams(extra);
            Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
            return params.toString();
        },
        [filters],
    );

    useEffect(() => {
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        request(`/api/v1/electronic-journal-entries?${queryFor({ per_page: String(PER_PAGE), page: String(page) })}`).then((response) => {
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
    }, [queryFor, page, reloadKey]);

    function change(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
        setOpenId(null);
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    async function exportCsv() {
        setExporting(true);
        setExportError(null);
        try {
            const query = queryFor();
            const response = await fetch(`/api/v1/electronic-journal-entries${query ? `?${query}` : ''}`, {
                headers: { Accept: 'text/csv' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                if (response.status === 401) {
                    signIn();
                    return;
                }
                setExportError(response.status === 403 ? 'Your role does not allow exporting the journal.' : 'The export could not be prepared. Try again in a moment.');
                return;
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const range = filters.from || filters.to ? `_${filters.from || 'start'}_${filters.to || 'today'}` : `_${new Date().toISOString().slice(0, 10)}`;
            const filename = `electronic-journal${filters.event_type ? `_${filters.event_type.toLowerCase()}` : ''}${range}.csv`;
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            setToast(`Exported ${filename}`);
        } catch {
            setExportError('The export could not be prepared. Check your connection and try again.');
        } finally {
            setExporting(false);
        }
    }

    const entries = result?.data ?? [];
    const filtered = Object.values(filters).some((value) => value !== '');

    return (
        <AdminLayout title="Electronic journal" requiredCapability="JOURNAL_VIEW" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Electronic journal</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        A snapshot of every invoice, void, refund, reading, cash movement and stock change, taken at the moment it happened. It is read-only.
                    </p>
                </div>
                <button
                    type="button"
                    disabled={exporting}
                    onClick={exportCsv}
                    className="min-h-11 rounded-md border border-slate-600 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0"
                >
                    {exporting ? 'Preparing…' : 'Export CSV'}
                </button>
            </div>

            {exportError && <ErrorAlert message={exportError} onRetry={exportCsv} />}

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-3">
                <div>
                    <label htmlFor="journal_type" className="mb-1 block text-xs text-slate-500">
                        Type
                    </label>
                    <select id="journal_type" value={filters.event_type} onChange={(event) => change({ event_type: event.target.value })} className={fieldClass}>
                        <option value="">Every type</option>
                        {JOURNAL_TYPES.map((type) => (
                            <option key={type.id} value={type.id}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="journal_from" className="mb-1 block text-xs text-slate-500">
                        From
                    </label>
                    <input id="journal_from" type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
                <div>
                    <label htmlFor="journal_to" className="mb-1 block text-xs text-slate-500">
                        To
                    </label>
                    <input id="journal_to" type="date" value={filters.to} onChange={(event) => change({ to: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The journal could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading journal">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && entries.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'Nothing matches these filters.' : 'The journal is empty.'}</p>
                    {filtered && (
                        <button type="button" onClick={() => change(EMPTY)} className="mt-2 text-xs text-emerald-400 underline">
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {result && entries.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <ul className="divide-y divide-slate-800/70 overflow-hidden rounded-lg border border-slate-800 bg-slate-900" aria-label="Journal entries">
                        {entries.map((entry) => {
                            const open = openId === entry.id;
                            return (
                                <li key={entry.id}>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                                        <span className="w-40 shrink-0 font-mono text-[12px] text-slate-400">{formatDateTime(entry.occurred_at)}</span>
                                        <TypeBadge label={journalLabel(entry.event_type)} />
                                        <div className="min-w-0 flex-1 basis-64">
                                            <p className="break-words text-sm text-slate-100">{describeJournal(entry)}</p>
                                            <p className="font-mono text-[11px] text-slate-500">
                                                {entry.source_type} {shortId(entry.source_id)}
                                                {entry.terminal_id ? ` · terminal ${shortId(entry.terminal_id)}` : ''}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            aria-expanded={open}
                                            onClick={() => setOpenId(open ? null : entry.id)}
                                            className="min-h-11 text-xs text-emerald-400 hover:underline lg:min-h-0"
                                        >
                                            {open ? 'Hide' : 'Details'}
                                        </button>
                                    </div>
                                    {open && <Details entry={entry} />}
                                </li>
                            );
                        })}
                    </ul>
                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}
        </AdminLayout>
    );
}

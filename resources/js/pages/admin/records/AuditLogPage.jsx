import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime, shortId } from '../reports/formatters';
import { AUDIT_TYPES, KeyValues, TypeBadge, auditLabel, describeAudit } from './recordsParts';

const PER_PAGE = 25;
const EMPTY = { event_type: '', from: '', to: '' };
const fieldClass = 'min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0';

function Details({ event }) {
    return (
        <div className="space-y-3 border-t border-slate-800 bg-slate-950/60 px-4 py-3">
            <KeyValues
                data={{
                    id: event.id,
                    event_type: event.event_type,
                    actor_user_id: event.actor_user_id,
                    terminal_id: event.terminal_id,
                    entity_type: event.entity_type,
                    entity_id: event.entity_id,
                    reason: event.reason,
                    request_id: event.request_id,
                    occurred_at: event.occurred_at,
                }}
            />
            {event.before_metadata && (
                <div>
                    <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-slate-500">Before</p>
                    <KeyValues data={event.before_metadata} />
                </div>
            )}
            {event.after_metadata && (
                <div>
                    <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-slate-500">After</p>
                    <KeyValues data={event.after_metadata} />
                </div>
            )}
        </div>
    );
}

/**
 * /admin/audit -- auditEventList (AUDIT_VIEW). The append-only record of who did what and when. It cannot
 * be edited from anywhere. Each row reads as one sentence; "Details" shows every field the event holds.
 */
export default function AuditLogPage() {
    const { setUser } = useAuth();
    const [filters, setFilters] = useState(EMPTY);
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [openId, setOpenId] = useState(null);
    const seq = useRef(0);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
        setLoading(true);
        setFailure(null);
        request(`/api/v1/audit-events?${params.toString()}`).then((response) => {
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

    const events = result?.data ?? [];
    const filtered = Object.values(filters).some((value) => value !== '');

    return (
        <AdminLayout title="Audit log" requiredCapability="AUDIT_VIEW" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Audit log</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">Who did what, and when. Entries are added by the system as things happen and can never be changed or removed.</p>
            </div>

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-3">
                <div>
                    <label htmlFor="audit_type" className="mb-1 block text-xs text-slate-500">
                        What happened
                    </label>
                    <select id="audit_type" value={filters.event_type} onChange={(event) => change({ event_type: event.target.value })} className={fieldClass}>
                        <option value="">Everything</option>
                        {AUDIT_TYPES.map((type) => (
                            <option key={type.id} value={type.id}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="audit_from" className="mb-1 block text-xs text-slate-500">
                        From
                    </label>
                    <input id="audit_from" type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
                <div>
                    <label htmlFor="audit_to" className="mb-1 block text-xs text-slate-500">
                        To
                    </label>
                    <input id="audit_to" type="date" value={filters.to} onChange={(event) => change({ to: event.target.value })} className={`${fieldClass} [color-scheme:dark]`} />
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The audit log could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading audit log">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && events.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'Nothing matches these filters.' : 'Nothing has been recorded yet.'}</p>
                    {filtered && (
                        <button type="button" onClick={() => change(EMPTY)} className="mt-2 text-xs text-emerald-400 underline">
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {result && events.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <ul className="divide-y divide-slate-800/70 overflow-hidden rounded-lg border border-slate-800 bg-slate-900" aria-label="Audit events">
                        {events.map((event) => {
                            const open = openId === event.id;
                            return (
                                <li key={event.id}>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                                        <span className="w-40 shrink-0 font-mono text-[12px] text-slate-400">{formatDateTime(event.occurred_at)}</span>
                                        <TypeBadge label={auditLabel(event.event_type)} />
                                        <div className="min-w-0 flex-1 basis-64">
                                            <p className="break-words text-sm text-slate-100">{describeAudit(event)}</p>
                                            <p className="font-mono text-[11px] text-slate-500">
                                                {event.actor_user_id ? `by ${shortId(event.actor_user_id)}` : 'by the system'}
                                                {event.terminal_id ? ` · terminal ${shortId(event.terminal_id)}` : ''}
                                                {event.reason ? ` · “${event.reason}”` : ''}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            aria-expanded={open}
                                            onClick={() => setOpenId(open ? null : event.id)}
                                            className="min-h-11 text-xs text-emerald-400 hover:underline lg:min-h-0"
                                        >
                                            {open ? 'Hide' : 'Details'}
                                        </button>
                                    </div>
                                    {open && <Details event={event} />}
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

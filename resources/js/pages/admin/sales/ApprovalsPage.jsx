import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime, formatMoney, shortId } from '../reports/formatters';
import ApprovalReviewPanel from './ApprovalReviewPanel';
import { StatusBadge, useTerminalState } from './salesParts';

const PER_PAGE = 25;

/**
 * /admin/sales/approvals -- voidList / refundList, pending first. A cashier can only request a void or a
 * refund; this is where a manager decides it. Opening a row shows exactly what was asked for and lets the
 * manager approve (which carries it out, at this terminal) or reject it with a reason.
 */
export default function ApprovalsPage() {
    const { user, setUser } = useAuth();
    const terminalState = useTerminalState();
    const canRefunds = user.capabilities.includes('SALE_REFUND_APPROVE');
    const [kind, setKind] = useState('void'); // void | refund
    const [scope, setScope] = useState('pending'); // pending | all
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [review, setReview] = useState(null);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closeReview = useCallback(() => setReview(null), []);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (scope === 'pending') {
            params.set('status', 'REQUESTED');
        }
        setLoading(true);
        setFailure(null);
        request(`/api/v1/${kind === 'void' ? 'voids' : 'refunds'}?${params.toString()}`).then((response) => {
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
    }, [kind, scope, page, reloadKey]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    function decided(body, path) {
        setReview(null);
        if (path === 'reject') {
            setToast(kind === 'void' ? 'Void request rejected' : 'Refund request rejected');
        } else {
            setToast(kind === 'void' ? 'Void approved. The sale is voided and its items are back in stock.' : `Refund approved: ${formatMoney(body.refund_total)} returned`);
        }
        setReloadKey((key) => key + 1);
    }

    const rows = result?.data ?? [];
    const tab = (id, label) => (
        <button
            key={id}
            type="button"
            aria-pressed={kind === id}
            onClick={() => {
                setKind(id);
                setPage(1);
            }}
            className={`min-h-11 flex-1 px-4 py-1.5 text-sm md:flex-none lg:min-h-0 ${kind === id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'}`}
        >
            {label}
        </button>
    );

    return (
        <AdminLayout title="Approvals" requiredCapability="SALE_VOID_APPROVE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Approvals</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Voids and refunds that a cashier asked for and that still need a manager&apos;s decision. Nothing changes on the sale, in stock or in the drawer until you approve it.
                </p>
            </div>

            <div className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:items-end">
                <div role="group" aria-label="Kind">
                    <span className="mb-1 block text-xs text-slate-500">Show</span>
                    <div className="flex overflow-hidden rounded-md border border-slate-700">
                        {tab('void', 'Voids')}
                        {canRefunds && tab('refund', 'Refunds')}
                    </div>
                </div>
                <div role="group" aria-label="Scope">
                    <span className="mb-1 block text-xs text-slate-500">Status</span>
                    <div className="flex overflow-hidden rounded-md border border-slate-700">
                        {[
                            ['pending', 'Waiting'],
                            ['all', 'All'],
                        ].map(([id, label]) => (
                            <button
                                key={id}
                                type="button"
                                aria-pressed={scope === id}
                                onClick={() => {
                                    setScope(id);
                                    setPage(1);
                                }}
                                className={`min-h-11 flex-1 px-3 py-1.5 text-xs md:flex-none lg:min-h-0 ${scope === id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {terminalState === 'not-enrolled' && (
                <div role="status" className="mb-3 rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-200">
                    This browser is not enrolled as a terminal, so you can review and reject requests here but not approve them.{' '}
                    {user.capabilities.includes('TERMINAL_MANAGE') ? (
                        <Link to="/terminals" className="text-emerald-400 underline">
                            Enroll this browser
                        </Link>
                    ) : (
                        'Ask an administrator to enroll it.'
                    )}
                </div>
            )}

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The requests could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading requests">
                    {[0, 1, 2].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">
                        {scope === 'pending' ? `No ${kind === 'void' ? 'voids' : 'refunds'} are waiting for a decision.` : `No ${kind === 'void' ? 'voids' : 'refunds'} have been requested yet.`}
                    </p>
                </div>
            )}

            {result && rows.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <ul className="divide-y divide-slate-800/70 overflow-hidden rounded-lg border border-slate-800" aria-label="Requests">
                        {rows.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 bg-slate-900 px-4 py-3 hover:bg-slate-800/60">
                                <div className="min-w-0 flex-1">
                                    <p className="break-words text-sm text-slate-100">{row.reason}</p>
                                    <p className="mt-0.5 font-mono text-[11px] text-slate-500">
                                        {formatDateTime(row.requested_at)} &middot; requested by {shortId(row.requested_by)} &middot; sale {shortId(row.sale_id)}
                                    </p>
                                </div>
                                {kind === 'refund' && <span className="font-mono tabular-nums text-slate-100">{formatMoney(row.refund_total)}</span>}
                                <StatusBadge status={row.status} />
                                <button
                                    type="button"
                                    onClick={() => setReview(row)}
                                    className="min-h-11 rounded-md border border-slate-600 px-3 py-1.5 text-xs text-slate-200 hover:bg-slate-800 lg:min-h-0"
                                >
                                    {row.status === 'REQUESTED' ? 'Review' : 'View'}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {review && (
                <ApprovalReviewPanel
                    key={`${kind}:${review.id}`}
                    kind={kind}
                    summary={review}
                    terminalState={terminalState}
                    onDone={decided}
                    onClose={closeReview}
                    onUnauthorized={signIn}
                />
            )}
        </AdminLayout>
    );
}

import { useCallback, useEffect, useState } from 'react';
import AdminLayout from './AdminLayout';
import { ConfirmDialog, ErrorAlert } from './catalog/CatalogParts';
import { failureMessage, request } from './catalog/catalogApi';
import { fieldClass, useLoad, useSignIn } from './records/historyParts';
import { formatDateTime } from './reports/formatters';

/**
 * /admin/terminals -- terminal enrollment and revocation (TERMINAL_MANAGE).
 *
 * Every POS_TERMINAL-classified operation (shiftOpen, saleFinalize, shifts/current) needs the
 * `tindaflow_terminal` credential ADR-011 establishes here, so nothing at the till works until a
 * browser is enrolled. Terminals themselves are not created here: no terminalCreate operation
 * exists in the contract, so they must already exist before they can be enrolled.
 *
 * What this screen deliberately does NOT claim: whether a terminal is revoked. Revocation writes
 * `revoked_at` and never touches `status` (module-a §14 Ruling 3/9), and TerminalSummary -- the only
 * shape terminalList and terminalGet return -- excludes `revoked_at`. So revocation is real and
 * enforced (TerminalCredentialResolver raises TERMINAL_REVOKED), but it is not readable back. Rather
 * than invent a REVOKED badge the API cannot support, a terminal revoked in THIS session is marked
 * from local state and said to be exactly that; anything revoked earlier or from another browser is
 * indistinguishable here until `revoked_at` joins the contract.
 */
export default function TerminalsPage() {
    const signIn = useSignIn();
    const { data: result, failure, loading, reload } = useLoad('/api/v1/terminals?per_page=100');

    // The terminal this browser currently holds a credential for, or why it holds none.
    const [thisBrowser, setThisBrowser] = useState(undefined); // undefined = still resolving
    const [browserProblem, setBrowserProblem] = useState(null);

    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [issued, setIssued] = useState(null); // { terminalId, token, expiresAt }
    const [pastedToken, setPastedToken] = useState('');
    const [confirmRevoke, setConfirmRevoke] = useState(null); // the terminal awaiting confirmation
    const [revokedHere, setRevokedHere] = useState(() => new Set()); // ids revoked in this session

    const loadThisBrowser = useCallback(async () => {
        const response = await request('/api/v1/terminal/current');
        if (response.ok) {
            setThisBrowser(response.body);
            setBrowserProblem(null);
            return;
        }
        setThisBrowser(null);
        // The contract gives these two separate codes precisely so they are not conflated.
        setBrowserProblem(
            response.body?.error?.code === 'TERMINAL_REVOKED'
                ? "This browser's terminal credential has been revoked. Enroll it again to use the till."
                : null,
        );
    }, []);

    useEffect(() => {
        loadThisBrowser();
    }, [loadThisBrowser]);

    async function generateToken(terminal) {
        setBusy(true);
        setActionError(null);
        setNotice(null);
        const response = await request('/api/v1/terminal-enrollment-tokens', { method: 'POST', body: { terminal_id: terminal.id } });
        if (response.ok) {
            setIssued({ terminalId: terminal.id, terminalCode: terminal.terminal_code, token: response.body.token, expiresAt: response.body.expires_at });
        } else {
            setActionError(failureMessage(response, 'The enrollment token could not be generated.'));
        }
        setBusy(false);
    }

    async function enrollThisBrowser(token) {
        setBusy(true);
        setActionError(null);
        const response = await request('/api/v1/terminal/enroll', { method: 'POST', body: { token } });
        if (response.ok) {
            setThisBrowser(response.body);
            setBrowserProblem(null);
            setIssued(null);
            setPastedToken('');
            setNotice(`This browser is now enrolled as ${response.body.terminal_code}.`);
        } else {
            setActionError(
                failureMessage(response, 'Enrollment failed. The token may be invalid, already used, or expired — generate a new one.'),
            );
        }
        setBusy(false);
    }

    async function revoke(terminal) {
        setBusy(true);
        setActionError(null);
        setNotice(null);
        const response = await request(`/api/v1/terminals/${terminal.id}/revoke`, { method: 'POST' });
        if (response.ok) {
            setRevokedHere((prior) => new Set(prior).add(terminal.id));
            setNotice(`${terminal.terminal_code} was revoked. Any browser still holding its credential is now locked out of the till.`);
            // Revoking the credential this browser holds invalidates it too.
            if (thisBrowser?.id === terminal.id) {
                loadThisBrowser();
            }
            reload();
        } else {
            setActionError(failureMessage(response, 'The terminal could not be revoked.'));
        }
        setConfirmRevoke(null);
        setBusy(false);
    }

    const terminals = result?.data ?? [];

    return (
        <AdminLayout title="Terminals" requiredCapability="TERMINAL_MANAGE" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Terminals</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    A till can only sell from a browser that holds a terminal credential. Generate a one-time enrollment token for a terminal, then
                    enter it on the machine that will run the till.
                </p>
            </div>

            {notice && (
                <p role="status" className="mb-3 rounded-md border border-emerald-800/60 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-300">
                    {notice}
                </p>
            )}
            {actionError && <ErrorAlert message={actionError} onRetry={() => setActionError(null)} />}

            <section className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
                <h3 className="text-sm font-semibold text-slate-100">This browser</h3>
                {thisBrowser === undefined ? (
                    <p className="mt-2 text-sm text-slate-500">Checking…</p>
                ) : thisBrowser ? (
                    <p className="mt-2 text-sm text-slate-300">
                        Enrolled as <span className="font-mono text-emerald-400">{thisBrowser.terminal_code}</span>
                        {thisBrowser.activated_at && <span className="text-slate-500"> · activated {formatDateTime(thisBrowser.activated_at)}</span>}
                    </p>
                ) : (
                    <>
                        <p className="mt-2 text-sm text-amber-400">
                            {browserProblem ?? 'This browser is not enrolled as a terminal, so it cannot open a shift or complete a sale.'}
                        </p>
                        <div className="mt-3 flex flex-wrap items-end gap-2">
                            <div className="min-w-0 flex-1">
                                <label htmlFor="enrollment_token" className="mb-1 block text-xs text-slate-500">
                                    Enrollment token
                                </label>
                                <input
                                    id="enrollment_token"
                                    type="text"
                                    value={pastedToken}
                                    onChange={(event) => setPastedToken(event.target.value)}
                                    placeholder="Paste the token issued for this terminal"
                                    className={`${fieldClass} font-mono`}
                                />
                            </div>
                            <button
                                type="button"
                                disabled={busy || pastedToken.trim() === ''}
                                onClick={() => enrollThisBrowser(pastedToken.trim())}
                                className="min-h-11 rounded-md bg-emerald-500 px-4 text-sm font-bold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500 lg:min-h-0 lg:py-2"
                            >
                                Enroll this browser
                            </button>
                        </div>
                    </>
                )}
            </section>

            {issued && (
                <section className="mb-4 rounded-lg border border-emerald-800/60 bg-slate-900 p-4">
                    <h3 className="text-sm font-semibold text-slate-100">Enrollment token for {issued.terminalCode}</h3>
                    <p className="mt-0.5 text-xs text-amber-400">Shown once. Copy it now — it cannot be retrieved again.</p>
                    <code className="mt-2 block break-all rounded border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-emerald-300">
                        {issued.token}
                    </code>
                    <p className="mt-1 font-mono text-[11px] text-slate-500">Expires {formatDateTime(issued.expiresAt)}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => enrollThisBrowser(issued.token)}
                            className="min-h-11 rounded-md border border-slate-700 px-3 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0 lg:py-2"
                        >
                            Enroll this browser with it
                        </button>
                        <button type="button" onClick={() => setIssued(null)} className="min-h-11 px-3 text-sm text-slate-400 underline lg:min-h-0">
                            Done
                        </button>
                    </div>
                </section>
            )}

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The terminals could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading terminals">
                    {[0, 1, 2].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && terminals.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">No terminals exist for this store yet.</p>
                    <p className="mt-1 text-xs text-slate-500">
                        Terminals are provisioned with the store, not created here — there is no operation in the API for adding one.
                    </p>
                </div>
            )}

            {result && terminals.length > 0 && (
                <section>
                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-500">Store terminals</h3>
                    <div className="overflow-x-auto rounded-lg border border-slate-800">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Terminal', 'Status', 'Activated', ''].map((heading, index) => (
                                        <th key={heading || index} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {terminals.map((terminal) => {
                                    const isThisBrowser = thisBrowser?.id === terminal.id;
                                    const justRevoked = revokedHere.has(terminal.id);
                                    return (
                                        <tr key={terminal.id} className={`odd:bg-slate-950/40 hover:bg-slate-900 ${justRevoked ? 'opacity-60' : ''}`}>
                                            <td className="px-3 py-2 font-mono text-[13px] text-slate-100">
                                                {terminal.terminal_code}
                                                {isThisBrowser && (
                                                    <span className="ml-2 rounded border border-emerald-700/70 px-1.5 py-0.5 font-mono text-[10px] text-emerald-400">
                                                        THIS BROWSER
                                                    </span>
                                                )}
                                                {justRevoked && (
                                                    <span className="ml-2 rounded border border-rose-700/70 px-1.5 py-0.5 font-mono text-[10px] text-rose-400">
                                                        REVOKED JUST NOW
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="inline-block rounded border border-slate-600 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-slate-300">
                                                    {terminal.status}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-[12px] text-slate-400">
                                                {terminal.activated_at ? formatDateTime(terminal.activated_at) : '—'}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => generateToken(terminal)}
                                                    className="min-h-11 rounded border border-slate-700 px-3 text-xs text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0 lg:py-1.5"
                                                >
                                                    Enrollment token
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={busy || justRevoked}
                                                    onClick={() => setConfirmRevoke(terminal)}
                                                    className="ml-2 min-h-11 rounded border border-rose-800/60 px-3 text-xs text-rose-400 hover:bg-rose-600 hover:text-white disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-rose-400 lg:min-h-0 lg:py-1.5"
                                                >
                                                    Revoke
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-xs text-slate-500">
                        Revocation is recorded against the terminal but is not returned by the API, so a terminal revoked earlier or from another
                        browser looks no different here. Only revocations made in this session are marked.
                    </p>
                </section>
            )}

            {confirmRevoke && (
                <ConfirmDialog
                    title={`Revoke ${confirmRevoke.terminal_code}?`}
                    body={
                        `Any browser holding this terminal's credential is locked out of the till immediately — it cannot open a shift, sell, or print. ` +
                        `A shift already open on it must be closed from that machine first. ` +
                        `To use it again, enroll it with a new token.` +
                        (thisBrowser?.id === confirmRevoke.id ? ' This is the terminal this browser is enrolled as.' : '')
                    }
                    confirmLabel="Revoke terminal"
                    busy={busy}
                    onConfirm={() => revoke(confirmRevoke)}
                    onCancel={() => setConfirmRevoke(null)}
                />
            )}
        </AdminLayout>
    );
}

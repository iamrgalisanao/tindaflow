import { useEffect, useState } from 'react';
import { failureMessage, fieldErrors, request } from '../catalog/catalogApi';
import { statusText } from './overrideCodeForm';

/** Roles whose fixed capabilities include CREDIT_LIMIT_OVERRIDE (RoleCapabilityCatalog); a cashier can never hold a code. */
const CAN_HOLD_A_CODE = ['ADMIN', 'MANAGER'];

/**
 * The administrator's side of an override code (creditOverrideCodeList / creditOverrideCodeReset, ADR-016 section 6): whether this user holds one,
 * and a way to CLEAR it after re-entering the administrator's own password. Clearing also lifts a lockout. The administrator never sees or
 * chooses the code: the user sets a new one themselves under "My override code".
 */
export default function OverrideCodeReset({ user }) {
    const [status, setStatus] = useState(null);
    const [failure, setFailure] = useState(null);
    const [confirming, setConfirming] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [cleared, setCleared] = useState(false);
    const applies = CAN_HOLD_A_CODE.includes(user.role);

    useEffect(() => {
        if (!applies) {
            return undefined;
        }
        let live = true;
        request('/api/v1/credit-override-codes').then((result) => {
            if (!live) {
                return;
            }
            if (result.ok) {
                setStatus(result.body.data.find((row) => row.user_id === user.id) ?? { has_code: false, locked: false });
            } else {
                setFailure(failureMessage(result, 'The override code status could not be loaded.'));
            }
        });

        return () => {
            live = false;
        };
    }, [applies, user.id]);

    if (!applies) {
        return null;
    }

    async function clear(event) {
        event.preventDefault();
        if (password === '') {
            setError('Enter your own password to confirm.');
            return;
        }
        setBusy(true);
        setError(null);
        const result = await request(`/api/v1/users/${user.id}/override-code/reset`, { method: 'POST', body: { password } });
        setBusy(false);
        setPassword('');
        if (result.ok) {
            setStatus(result.body);
            setConfirming(false);
            setCleared(true);
            return;
        }
        setError(fieldErrors(result).password ?? (result.status === 429 ? 'Too many tries. Wait a few minutes, then try again.' : failureMessage(result, 'The code could not be cleared.')));
    }

    return (
        <div className="border-t border-slate-800 pt-4" aria-label="Credit override code">
            <p className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Credit override code</p>
            <p className="mb-2 mt-1 text-xs text-slate-400">
                Lets this user approve one credit sale past an employee&apos;s limit. Only they can set it, under &ldquo;My override code&rdquo;. If they forgot it, or it is locked, clear it and they set a new one.
            </p>
            {failure && <p role="alert" className="text-xs text-rose-400">{failure}</p>}
            {status && (
                <p className="text-xs text-slate-300">
                    Status: <span data-testid="override-status">{statusText(status)}</span>
                </p>
            )}
            {cleared && <p role="status" className="mt-1 text-xs text-emerald-400">Cleared. Ask them to set a new code.</p>}

            {status && (status.has_code || status.locked) && !confirming && (
                <button type="button" onClick={() => setConfirming(true)} className="mt-2 min-h-11 rounded border border-rose-700 px-3 py-1.5 text-xs font-medium text-rose-400 hover:bg-rose-950/40 lg:min-h-0">
                    Clear override code
                </button>
            )}
            {confirming && (
                <div className="mt-2 space-y-2">
                    <label htmlFor="override_reset_password" className="block text-xs text-slate-400">
                        Your password, to confirm
                    </label>
                    <input
                        id="override_reset_password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => {
                            setPassword(event.target.value);
                            setError(null);
                        }}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0"
                    />
                    {error && <p role="alert" className="font-mono text-[11px] text-rose-400">&#9888; {error}</p>}
                    <div className="flex gap-2">
                        <button type="button" onClick={clear} disabled={busy} className="min-h-11 rounded bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-500 disabled:opacity-50 lg:min-h-0">
                            {busy ? 'Clearing…' : 'Clear the code'}
                        </button>
                        <button type="button" onClick={() => { setConfirming(false); setPassword(''); setError(null); }} className="min-h-11 rounded px-3 text-xs text-slate-300 hover:text-white lg:min-h-0">
                            Keep it
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

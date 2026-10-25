import { useEffect, useRef, useState } from 'react';
import { request } from '../admin/catalog/catalogApi';
import { OVERRIDE_CODE_MAX, OVERRIDE_CODE_MIN, cleanOverrideCode, grantFromResponse, money, overrideFailureText } from './credit';

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

/**
 * "Ask a manager to approve" (ADR-016 section 6, slice 3.3): the cashier picks the approving manager BY NAME and the manager types their
 * own code on the keypad. The cashier never sees the code (it is masked) and never learns how many tries are left. The code lives only in
 * this component's state, is cleared the moment it is sent (whatever the answer) and when the panel closes, and is never logged, stored or
 * put in a URL. A good code returns a single-use approval for THIS sale, which the till sends with the sale; it is not remembered anywhere else.
 *
 * `amount` is the sale total the server refused (the figure the approver is shown and the approval's ceiling).
 */
export default function OverrideApproval({ employee, amount, busy, onApproved, onCancel }) {
    const [approvers, setApprovers] = useState(null); // null = loading
    const [loadFailure, setLoadFailure] = useState(null);
    const [approverId, setApproverId] = useState(null);
    const [code, setCode] = useState('');
    const [checking, setChecking] = useState(false);
    const [message, setMessage] = useState(null);
    const codeRef = useRef(null);

    useEffect(() => {
        let live = true;
        request('/api/v1/credit-override-approvers').then((result) => {
            if (!live) {
                return;
            }
            if (result.ok) {
                setApprovers(result.body.data);
            } else {
                setApprovers([]);
                setLoadFailure(result.status === 401 ? 'Your session expired. Sign in again.' : 'The list of managers could not be loaded. Try again.');
            }
        });

        return () => {
            live = false;
            setCode('');
        };
    }, []);

    const working = checking || busy;
    const ready = approverId !== null && code.length >= OVERRIDE_CODE_MIN && !working;

    function choose(id) {
        setApproverId(id);
        setMessage(null);
        setCode('');
        codeRef.current?.focus();
    }

    function press(digit) {
        setMessage(null);
        setCode((current) => cleanOverrideCode(current + digit));
    }

    async function submit() {
        if (!ready) {
            return;
        }
        const typed = code;
        setCode(''); // gone from the screen and from state before the answer comes back
        setChecking(true);
        setMessage(null);
        const result = await request('/api/v1/credit-overrides', {
            method: 'POST',
            body: { approver_id: approverId, code: typed, employee_id: employee.id, amount },
        });
        setChecking(false);

        if (result.ok) {
            onApproved(grantFromResponse(result.body));
            return;
        }
        setMessage(overrideFailureText(result));
        codeRef.current?.focus();
    }

    return (
        // Not a <form>: this panel sits inside the credit confirmation's own form, and a form inside a form would also submit the sale.
        <div role="group" aria-label="Manager approval" className="mt-3 rounded-lg border border-amber-500/40 bg-slate-950 p-3 text-slate-100">
            <p className="text-sm font-semibold">Manager approval</p>
            <p className="text-xs text-slate-400">
                One approval covers this sale only: {money(amount)} to {employee.name}. The manager types their own code; you do not see it.
            </p>

            {approvers === null && <p className="mt-2 text-xs text-slate-400">Loading managers…</p>}
            {loadFailure && (
                <p role="alert" className="mt-2 text-xs text-red-300">
                    {loadFailure}
                </p>
            )}
            {approvers !== null && approvers.length === 0 && !loadFailure && (
                <p role="status" className="mt-2 text-xs text-amber-200">
                    No manager has set an override code yet. A manager sets one under &ldquo;My override code&rdquo; in the back office.
                </p>
            )}

            {approvers !== null && approvers.length > 0 && (
                <>
                    <fieldset className="mt-3">
                        <legend className="mb-1 text-[11px] uppercase tracking-wide text-slate-400">Who is approving?</legend>
                        <div className="flex flex-wrap gap-2">
                            {approvers.map((approver) => (
                                <button
                                    key={approver.id}
                                    type="button"
                                    aria-pressed={approverId === approver.id}
                                    onClick={() => choose(approver.id)}
                                    disabled={working}
                                    className={`min-h-12 rounded-lg border px-4 text-sm font-semibold ${
                                        approverId === approver.id ? 'border-amber-400 bg-amber-400/20 text-amber-100' : 'border-slate-600 text-slate-200 hover:bg-slate-800'
                                    } disabled:opacity-50`}
                                >
                                    {approver.name}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <div className="mt-3 max-w-xs">
                        <label htmlFor="override_code" className="mb-1 block text-[11px] uppercase tracking-wide text-slate-400">
                            Manager&apos;s code
                        </label>
                        <input
                            id="override_code"
                            ref={codeRef}
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            data-lpignore="true"
                            maxLength={OVERRIDE_CODE_MAX}
                            value={code}
                            disabled={approverId === null || working}
                            onChange={(event) => {
                                setMessage(null);
                                setCode(cleanOverrideCode(event.target.value));
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault(); // Enter approves; it must not also submit the sale form around this panel
                                    submit();
                                }
                            }}
                            aria-describedby={message ? 'override_message' : undefined}
                            className="min-h-12 w-full rounded-md border border-slate-600 bg-slate-900 px-3 text-center font-mono text-2xl tracking-[0.4em] text-slate-100 focus:border-amber-400 focus:outline-none disabled:opacity-50"
                        />
                        <div role="group" aria-label="Code keypad" className="mt-2 grid grid-cols-3 gap-2">
                            {KEYS.map((digit) => (
                                <button key={digit} type="button" onClick={() => press(digit)} disabled={approverId === null || working} className="min-h-12 rounded-lg border border-slate-600 text-xl font-bold text-slate-100 hover:bg-slate-800 disabled:opacity-40">
                                    {digit}
                                </button>
                            ))}
                            <button type="button" onClick={() => setCode('')} disabled={approverId === null || working} className="min-h-12 rounded-lg border border-slate-600 text-xs font-bold uppercase text-slate-300 hover:bg-slate-800 disabled:opacity-40">
                                Clear
                            </button>
                            <button type="button" onClick={() => press('0')} disabled={approverId === null || working} className="min-h-12 rounded-lg border border-slate-600 text-xl font-bold text-slate-100 hover:bg-slate-800 disabled:opacity-40">
                                0
                            </button>
                            <button type="button" aria-label="Delete last digit" onClick={() => setCode((current) => current.slice(0, -1))} disabled={approverId === null || working} className="min-h-12 rounded-lg border border-slate-600 text-xl font-bold text-slate-300 hover:bg-slate-800 disabled:opacity-40">
                                &larr;
                            </button>
                        </div>
                    </div>
                </>
            )}

            {message && (
                <p id="override_message" role="alert" className="mt-2 rounded border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                    {message}
                </p>
            )}

            <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" onClick={onCancel} disabled={working} className="min-h-12 rounded-lg border border-slate-600 px-5 text-sm font-bold uppercase tracking-wide text-slate-200 hover:bg-slate-800 disabled:opacity-50">
                    Cancel
                </button>
                <button type="button" onClick={submit} disabled={!ready} className="min-h-12 flex-1 rounded-lg bg-amber-400 px-4 text-base font-bold text-slate-950 hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-50">
                    {checking ? 'Checking…' : busy ? 'Charging…' : 'Approve this sale'}
                </button>
            </div>
        </div>
    );
}

import { useEffect, useRef, useState } from 'react';
import { request } from '../admin/catalog/catalogApi';
import { lookupFailureText, money, payerFromLookup, periodText, remainingCents, typeLabel } from './credit';

/**
 * The employee box of a credit sale (ADR-016 slice 3.2): one text box that takes what a keyboard-wedge scanner types (the card's code,
 * then Enter) or a code typed by hand, looks the employee up, and shows who it is and what is left of their limit for the current
 * cut-off before the cashier continues. Nothing is charged here; the server checks the limit again at checkout.
 */
export function EmployeeBox({ onFound, onCancel, initialCode = '', cancelLabel = 'Cancel' }) {
    const [code, setCode] = useState(initialCode);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [found, setFound] = useState(null);
    const inputRef = useRef(null);
    const continueRef = useRef(null);

    useEffect(() => {
        const input = inputRef.current;
        input?.focus();
        // A character that started the box (a scanner typing on the start screen) must not be selected and overwritten by the next one.
        input?.setSelectionRange(input.value.length, input.value.length);
    }, []);

    useEffect(() => {
        if (found) {
            continueRef.current?.focus();
        }
    }, [found]);

    async function lookUp(event) {
        event.preventDefault();
        const typed = code.trim();
        if (typed === '' || busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setFound(null);
        const result = await request(`/api/v1/employees/lookup?code=${encodeURIComponent(typed)}`);
        if (result.ok) {
            setFound(payerFromLookup(result.body));
        } else {
            setError(lookupFailureText(result));
            inputRef.current?.focus();
            inputRef.current?.select();
        }
        setBusy(false);
    }

    const remaining = found ? remainingCents(found.credit) : null;

    return (
        <section aria-label="Employee" className="mx-auto w-full max-w-xl rounded-lg border border-slate-700 bg-slate-900 p-4">
            <form onSubmit={lookUp} className="space-y-2">
                <label htmlFor="employee_code" className="block text-sm font-medium text-slate-200">
                    Scan the employee card or type the code
                </label>
                <div className="flex gap-2">
                    <input
                        id="employee_code"
                        ref={inputRef}
                        type="text"
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        aria-label="Employee code"
                        placeholder="TSC00012"
                        value={code}
                        onChange={(event) => {
                            setCode(event.target.value);
                            setFound(null);
                            setError(null);
                        }}
                        className="min-h-14 min-w-0 flex-1 rounded-lg border border-slate-600 bg-slate-950 px-4 font-mono text-xl uppercase tracking-wider text-slate-100 placeholder:text-slate-600 focus:border-emerald-500 focus:outline-none"
                    />
                    <button
                        type="submit"
                        disabled={busy || code.trim() === ''}
                        className="min-h-14 rounded-lg border border-slate-600 px-5 text-sm font-bold uppercase tracking-wider text-slate-100 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {busy ? 'Looking…' : 'Find'}
                    </button>
                </div>
            </form>

            {error && (
                <p role="alert" className="mt-3 rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">
                    {error}
                </p>
            )}

            {found && (
                <div className="mt-3 space-y-3" aria-label="Employee found">
                    <div className="rounded-lg border border-emerald-700/60 bg-slate-950 p-3">
                        <p className="text-lg font-semibold text-slate-100">{found.name}</p>
                        <p className="font-mono text-xs text-slate-400">
                            {found.code} &middot; {typeLabel(found.type)}
                        </p>
                        <dl className="mt-3 grid grid-cols-3 gap-2 text-center">
                            {[
                                ['Limit', found.credit.limit],
                                ['Used', found.credit.used],
                                ['Remaining', found.credit.remaining],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded bg-slate-900 px-2 py-2">
                                    <dt className="text-[11px] uppercase tracking-wide text-slate-400">{label}</dt>
                                    <dd className={`font-mono text-base font-bold tabular-nums ${label === 'Remaining' ? 'text-emerald-400' : 'text-slate-100'}`}>{money(value)}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-2 text-xs text-slate-400">For {periodText(found.credit.periodStart, found.credit.periodEnd)}</p>
                        {remaining !== null && remaining <= 0n && (
                            <p role="alert" className="mt-2 rounded bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
                                No credit is left for this period, so a credit sale will be refused.
                            </p>
                        )}
                    </div>
                    <button
                        ref={continueRef}
                        type="button"
                        onClick={() => onFound(found)}
                        className="min-h-14 w-full rounded-lg bg-emerald-500 px-4 text-lg font-bold text-slate-950 hover:bg-emerald-400"
                    >
                        Continue
                    </button>
                </div>
            )}

            {onCancel && (
                <button type="button" onClick={onCancel} className="mt-3 min-h-11 text-sm text-slate-400 underline">
                    {cancelLabel}
                </button>
            )}
        </section>
    );
}

/**
 * The first screen of every sale (client decision, ADR-016): Cash and Credit, both visible, as two big buttons. Cash is one tap (or Enter:
 * it holds the focus) and opens the register as it always was. Credit opens the employee box. A scanner that types while nothing has
 * the focus starts the box too, so a card can simply be scanned without touching the screen.
 */
export default function SaleStart({ onCash, onCredit }) {
    const [choosing, setChoosing] = useState(false);
    const [seed, setSeed] = useState('');
    const cashRef = useRef(null);

    useEffect(() => {
        if (!choosing) {
            cashRef.current?.focus();
        }
    }, [choosing]);

    function onKeyDown(event) {
        const typing = event.target instanceof HTMLInputElement;
        if (!choosing && !typing && event.key.length === 1 && /[A-Za-z0-9]/.test(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            setSeed(event.key);
            setChoosing(true);
        }
    }

    return (
        <div onKeyDown={onKeyDown} className="mx-auto flex w-full max-w-3xl flex-col items-center gap-5 px-4 py-8" aria-label="Start of sale">
            <h2 className="text-xl font-semibold text-slate-100">Who is paying?</h2>
            <div className="grid w-full grid-cols-1 gap-4 sm:grid-cols-2">
                <button
                    ref={cashRef}
                    type="button"
                    onClick={onCash}
                    className="flex min-h-40 flex-col items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-6 py-8 text-slate-950 hover:bg-emerald-400 focus:outline-none focus:ring-4 focus:ring-emerald-300"
                >
                    <span className="text-4xl font-extrabold">Cash</span>
                    <span className="text-sm font-medium">Pay now at the till</span>
                </button>
                <button
                    type="button"
                    aria-expanded={choosing}
                    onClick={() => {
                        setSeed('');
                        setChoosing(true);
                    }}
                    className={`flex min-h-40 flex-col items-center justify-center gap-2 rounded-2xl border-2 px-6 py-8 text-slate-100 hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-sky-300 ${
                        choosing ? 'border-sky-400 bg-slate-800' : 'border-sky-500 bg-slate-900'
                    }`}
                >
                    <span className="text-4xl font-extrabold text-sky-300">Credit</span>
                    <span className="text-sm font-medium text-slate-300">Charge to an employee</span>
                </button>
            </div>
            {choosing && <EmployeeBox key={seed} initialCode={seed} onFound={onCredit} onCancel={() => setChoosing(false)} cancelLabel="Back to Cash or Credit" />}
        </div>
    );
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, request } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';
import { downloadCsv } from './billingApi';
import { asOfInstant, manilaToday } from './billingConfig';
import {
    PERIOD_STATUS,
    canWriteOff,
    checkAllocations,
    chequePayload,
    emptyChequeForm,
    emptyWriteOffForm,
    fromCents,
    newRequestId,
    openPeriods,
    periodStatusLabel,
    proposeAllocation,
    returnedChequesOf,
    toCents,
    validateCheque,
    validateWriteOff,
    writeOffPayload,
    writeOffStatusLabel,
} from './chequeConfig';

const FIELD = 'mt-1 block min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none lg:min-h-0';
const LABEL = 'block text-xs font-medium text-slate-400';
const ERROR = 'mt-1 text-xs text-rose-400';
const PER_PAGE = 25;

const TONES = {
    amber: 'border-amber-700 bg-amber-950/50 text-amber-300',
    sky: 'border-sky-700 bg-sky-950/50 text-sky-300',
    emerald: 'border-emerald-700 bg-emerald-950/50 text-emerald-300',
    rose: 'border-rose-700 bg-rose-950/50 text-rose-300',
    slate: 'border-slate-700 bg-slate-800 text-slate-300',
    violet: 'border-violet-700 bg-violet-950/50 text-violet-300',
};

function StatusBadge({ status }) {
    const tone = TONES[PERIOD_STATUS[status]?.tone ?? 'slate'];
    return <span className={`inline-block whitespace-nowrap rounded-full border px-2 py-0.5 text-xs ${tone}`}>{periodStatusLabel(status)}</span>;
}

function ChequeStatus({ status }) {
    return (
        <span className={`inline-block rounded-full border px-2 py-0.5 text-xs ${status === 'RETURNED' ? TONES.rose : TONES.emerald}`}>
            {status === 'RETURNED' ? 'Returned' : 'Received'}
        </span>
    );
}

function Tile({ label, value, tone = 'text-slate-100', hint }) {
    return (
        <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
            <div className="text-xs text-slate-400">{label}</div>
            <div className={`mt-1 font-mono text-lg tabular-nums ${tone}`}>{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
        </div>
    );
}

/** The form that marks a cheque returned: a reason, the date the bank sent it back, and an explicit confirm. */
function ReturnDialog({ cheque, busy, error, onConfirm, onCancel }) {
    const [reason, setReason] = useState('');
    const [returnedOn, setReturnedOn] = useState(manilaToday());
    const first = useRef(null);
    useEffect(() => {
        first.current?.focus();
        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onCancel();
            }
        }
        document.addEventListener('keydown', onKeyDown, true);
        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, [onCancel]);
    const valid = reason.trim().length >= 3 && /^\d{4}-\d{2}-\d{2}$/.test(returnedOn);

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-label="Mark cheque returned">
            <div className="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" onClick={onCancel} aria-hidden="true" />
            <form
                className="relative w-full max-w-md rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (valid && !busy) {
                        onConfirm({ returned_on: returnedOn, reason: reason.trim() });
                    }
                }}
            >
                <h3 className="text-base font-semibold text-slate-100">Mark cheque returned</h3>
                <p className="mt-2 text-sm text-slate-400">
                    Cheque {cheque.cheque_number} from {cheque.bank} for {formatMoney(cheque.amount)} stops counting as paid, and the amounts it paid are owed again. This cannot be undone; if the bank pays it
                    later, record it again as a new cheque.
                </p>
                <label className={`${LABEL} mt-4`}>
                    Why was it returned?
                    <input ref={first} className={FIELD} value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} placeholder="For example: Insufficient funds" />
                </label>
                <label className={`${LABEL} mt-3`}>
                    Date returned
                    <input type="date" className={FIELD} value={returnedOn} max={manilaToday()} onChange={(event) => setReturnedOn(event.target.value)} />
                </label>
                {error && <p role="alert" className={ERROR}>{error}</p>}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!valid || busy}
                        className="min-h-11 rounded-md border border-rose-600 bg-rose-950/60 px-3 py-2 text-sm font-medium text-rose-300 hover:bg-rose-600 hover:text-white disabled:opacity-50 lg:min-h-0"
                    >
                        {busy ? 'Working…' : 'Mark returned'}
                    </button>
                </div>
            </form>
        </div>
    );
}


/** Escape closes a dialog; focus starts on the first field. */
function useDialogKeys(onCancel, first) {
    useEffect(() => {
        first.current?.focus();
        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onCancel();
            }
        }
        document.addEventListener('keydown', onKeyDown, true);
        return () => document.removeEventListener('keydown', onKeyDown, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [onCancel]);
}

/**
 * Writes off an unpaid amount of one cut-off period (slice 4.3). Two steps, because this is money: the form (amount, required reason, an
 * optional returned cheque), then a plain confirmation that repeats the amount and the period. A write-off is not money received.
 */
function WriteOffDialog({ period, initialChequeId, busy, error, onConfirm, onCancel }) {
    const [form, setForm] = useState(() => emptyWriteOffForm(period, initialChequeId));
    const [step, setStep] = useState('form');
    const [errors, setErrors] = useState({});
    const [requestId] = useState(() => newRequestId());
    const first = useRef(null);
    useDialogKeys(onCancel, first);
    const returned = returnedChequesOf(period);
    const cheque = returned.find((item) => item.cheque_id === form.chequeId);
    const shown = { ...errors };

    function review(event) {
        event.preventDefault();
        const found = validateWriteOff(form, period);
        setErrors(found);
        if (Object.keys(found).length === 0) {
            setStep('confirm');
        }
    }

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-label="Write off an unpaid amount">
            <div className="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" onClick={onCancel} aria-hidden="true" />
            {step === 'form' ? (
                <form className="relative w-full max-w-md rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]" onSubmit={review} noValidate>
                    <h3 className="text-base font-semibold text-slate-100">Write off an unpaid amount</h3>
                    <p className="mt-2 text-sm text-slate-400">
                        {period.period_label} still owes {formatMoney(period.owed_amount)}. Write off the part that will never be paid. This does not record any money received; it only takes the amount off
                        what is owed. You can reverse it later.
                    </p>
                    <div className="mt-4">
                        <label className={LABEL}>
                            Amount to write off (₱)
                            <input ref={first} className={FIELD} inputMode="decimal" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} />
                        </label>
                        {shown.amount ? <p className={ERROR}>{shown.amount}</p> : null}
                    </div>
                    <div className="mt-3">
                        <label className={LABEL}>
                            Why is it being written off?
                            <input className={FIELD} value={form.reason} maxLength={255} onChange={(event) => setForm({ ...form, reason: event.target.value })} placeholder="For example: TSC will not pay the bounced cheque" />
                        </label>
                        {shown.reason ? <p className={ERROR}>{shown.reason}</p> : null}
                    </div>
                    {returned.length > 0 && (
                        <label className={`${LABEL} mt-3`}>
                            Returned cheque it relates to (optional)
                            <select className={FIELD} value={form.chequeId} onChange={(event) => setForm({ ...form, chequeId: event.target.value })}>
                                <option value="">None</option>
                                {returned.map((item) => (
                                    <option key={item.cheque_id} value={item.cheque_id}>
                                        {item.bank} {item.cheque_number} · {formatMoney(item.amount)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <div className="mt-5 flex justify-end gap-2">
                        <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                            Cancel
                        </button>
                        <button type="submit" className="min-h-11 rounded-md border border-violet-600 bg-violet-950/60 px-3 py-2 text-sm font-medium text-violet-200 hover:bg-violet-600 hover:text-white lg:min-h-0">
                            Review write-off
                        </button>
                    </div>
                </form>
            ) : (
                <div className="relative w-full max-w-md rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]">
                    <h3 className="text-base font-semibold text-slate-100">Confirm the write-off</h3>
                    <p className="mt-2 text-sm text-slate-200">
                        You are writing off <strong>{formatMoney(form.amount)}</strong> of <strong>{period.period_label}</strong> ({period.period_start} to {period.period_end}).
                    </p>
                    <p className="mt-2 text-sm text-slate-400">
                        The amount owed for this period goes from {formatMoney(period.owed_amount)} to {formatMoney(subtract(period.owed_amount, form.amount))}. No money is received and the cash drawer is not touched.
                    </p>
                    <p className="mt-2 text-sm text-slate-400">
                        Reason: {form.reason.trim()}
                        {cheque ? ` · Cheque ${cheque.bank} ${cheque.cheque_number}` : ''}
                    </p>
                    {error && (
                        <p role="alert" className={ERROR}>
                            {error}
                        </p>
                    )}
                    <div className="mt-5 flex justify-end gap-2">
                        <button type="button" disabled={busy} onClick={() => setStep('form')} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0">
                            Back
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => onConfirm(writeOffPayload(form, period, requestId))}
                            className="min-h-11 rounded-md border border-violet-600 bg-violet-950/60 px-3 py-2 text-sm font-medium text-violet-200 hover:bg-violet-600 hover:text-white disabled:opacity-50 lg:min-h-0"
                        >
                            {busy ? 'Working…' : `Write off ${formatMoney(form.amount)}`}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

function subtract(owed, amount) {
    const left = (toCents(owed) ?? 0n) - (toCents(amount) ?? 0n);
    return fromCents(left);
}

/** Cancels a write-off: a reason and an explicit confirm. The amount is owed again; nothing is deleted. */
function ReverseWriteOffDialog({ row, busy, error, onConfirm, onCancel }) {
    const [reason, setReason] = useState('');
    const first = useRef(null);
    useDialogKeys(onCancel, first);
    const valid = reason.trim().length >= 3;

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-label="Reverse a write-off">
            <div className="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" onClick={onCancel} aria-hidden="true" />
            <form
                className="relative w-full max-w-md rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (valid && !busy) {
                        onConfirm({ reason: reason.trim() });
                    }
                }}
            >
                <h3 className="text-base font-semibold text-slate-100">Reverse this write-off</h3>
                <p className="mt-2 text-sm text-slate-400">
                    The {formatMoney(row.amount)} written off for {row.period_label} will be owed again. The write-off stays in the list, marked reversed, with your reason. If the amount should be written off
                    again later, make a new write-off.
                </p>
                <label className={`${LABEL} mt-4`}>
                    Why is it being reversed?
                    <input ref={first} className={FIELD} value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} placeholder="For example: Written off by mistake" />
                </label>
                {error && (
                    <p role="alert" className={ERROR}>
                        {error}
                    </p>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!valid || busy}
                        className="min-h-11 rounded-md border border-rose-600 bg-rose-950/60 px-3 py-2 text-sm font-medium text-rose-300 hover:bg-rose-600 hover:text-white disabled:opacity-50 lg:min-h-0"
                    >
                        {busy ? 'Working…' : `Reverse ${formatMoney(row.amount)}`}
                    </button>
                </div>
            </form>
        </div>
    );
}

/** The record-a-cheque form, with the oldest-first proposal over the periods that still owe something. */
function ChequeForm({ periods, busy, serverErrors, onSubmit, onCancel }) {
    const [form, setForm] = useState(() => emptyChequeForm());
    const [typed, setTyped] = useState({});
    const [edited, setEdited] = useState(false); // once the user types an allocation, a new amount no longer overwrites it
    const [errors, setErrors] = useState({});
    const open = useMemo(() => openPeriods(periods), [periods]);
    const check = checkAllocations(periods, form.amount, typed);
    const proposal = useMemo(() => proposeAllocation(periods, form.amount), [periods, form.amount]);

    function setField(patch) {
        setForm((current) => ({ ...current, ...patch }));
    }

    function changeAmount(value) {
        setField({ amount: value });
        if (!edited) {
            setTyped(proposeAllocation(periods, value).allocations);
        }
    }

    function suggest() {
        setEdited(false);
        setTyped(proposal.allocations);
    }

    function submit(event) {
        event.preventDefault();
        const found = validateCheque(form);
        setErrors(found);
        if (Object.keys(found).length > 0 || !check.matches) {
            return;
        }
        onSubmit(chequePayload(form, typed));
    }

    const shown = { ...errors, ...serverErrors };
    const difference = check.difference;
    const sumLine =
        difference === null
            ? 'Enter the cheque amount to place it.'
            : difference === '0.00' && check.sum !== '0.00'
              ? `All ${formatMoney(check.sum)} is placed.`
              : difference.startsWith('-')
                ? `${formatMoney(difference.slice(1))} more than the cheque. Take it off a period.`
                : `${formatMoney(difference)} of the cheque is not placed yet.`;

    return (
        <form onSubmit={submit} noValidate aria-label="Record a cheque" className="mb-6 space-y-4 rounded-lg border border-emerald-800 bg-slate-900 p-4">
            <h3 className="text-base font-semibold text-slate-100">Record a cheque</h3>
            <p className="text-sm text-slate-400">
                A cheque is not a sale and does not touch the cash drawer or any shift. It only lowers what TSC owes for the periods you choose, and every peso of it must go to a period.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label className={LABEL}>
                    Cheque number
                    <input className={FIELD} value={form.chequeNumber} maxLength={40} onChange={(event) => setField({ chequeNumber: event.target.value })} />
                    {shown.chequeNumber ?? shown.cheque_number ? <span className={ERROR}>{shown.chequeNumber ?? shown.cheque_number}</span> : null}
                </label>
                <label className={LABEL}>
                    Bank
                    <input className={FIELD} value={form.bank} maxLength={80} onChange={(event) => setField({ bank: event.target.value })} />
                    {shown.bank ? <span className={ERROR}>{shown.bank}</span> : null}
                </label>
                <label className={LABEL}>
                    Amount (₱)
                    <input className={FIELD} inputMode="decimal" value={form.amount} onChange={(event) => changeAmount(event.target.value)} placeholder="0.00" />
                    {shown.amount ? <span className={ERROR}>{shown.amount}</span> : null}
                </label>
                <label className={LABEL}>
                    Date on the cheque
                    <input type="date" className={FIELD} value={form.chequeDate} onChange={(event) => setField({ chequeDate: event.target.value })} />
                    {shown.chequeDate ?? shown.cheque_date ? <span className={ERROR}>{shown.chequeDate ?? shown.cheque_date}</span> : null}
                </label>
                <label className={LABEL}>
                    Date received
                    <input type="date" className={FIELD} value={form.receivedOn} max={manilaToday()} onChange={(event) => setField({ receivedOn: event.target.value })} />
                    {shown.receivedOn ?? shown.received_on ? <span className={ERROR}>{shown.receivedOn ?? shown.received_on}</span> : null}
                </label>
                <label className={LABEL}>
                    Notes (optional)
                    <input className={FIELD} value={form.notes} maxLength={500} onChange={(event) => setField({ notes: event.target.value })} />
                </label>
            </div>

            <fieldset>
                <legend className="text-sm font-medium text-slate-200">Which periods does it pay?</legend>
                {open.length === 0 ? (
                    <p className="mt-2 text-sm text-slate-400">No period owes anything right now, so there is nothing for a cheque to pay.</p>
                ) : (
                    <>
                        <div className="mt-2 overflow-x-auto rounded-md border border-slate-800">
                            <table className="w-full min-w-[28rem] text-sm">
                                <thead className="border-b border-slate-800 text-xs text-slate-500">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 text-left font-normal">Period</th>
                                        <th scope="col" className="px-3 py-2 text-right font-normal">Still owed</th>
                                        <th scope="col" className="px-3 py-2 text-right font-normal">This cheque pays (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {open.map((period) => (
                                        <tr key={period.period_start} className="border-t border-slate-800 text-slate-200">
                                            <td className="px-3 py-2">{period.period_label}</td>
                                            <td className="px-3 py-2 text-right font-mono tabular-nums">{formatMoney(period.owed_amount)}</td>
                                            <td className="px-3 py-2 text-right">
                                                <input
                                                    aria-label={`Amount for ${period.period_label}`}
                                                    className={`${FIELD} ml-auto w-36 text-right font-mono`}
                                                    inputMode="decimal"
                                                    value={typed[period.period_start] ?? ''}
                                                    onChange={(event) => {
                                                        setEdited(true);
                                                        setTyped((current) => ({ ...current, [period.period_start]: event.target.value }));
                                                    }}
                                                />
                                                {check.rowErrors[period.period_start] && <span className={`${ERROR} block`}>{check.rowErrors[period.period_start]}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-3 text-sm">
                            <span role="status" className={check.matches ? 'text-emerald-300' : 'text-amber-300'}>
                                {sumLine}
                            </span>
                            <button type="button" onClick={suggest} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                                Suggest oldest first
                            </button>
                        </div>
                        {proposal.leftover !== '0.00' && (
                            <p className="mt-1 text-xs text-amber-300">
                                The cheque is {formatMoney(proposal.leftover)} more than all the periods owe. A cheque cannot be kept on account; check the amount, or record it when more is billed.
                            </p>
                        )}
                    </>
                )}
                {shown.allocations ? <p className={ERROR}>{shown.allocations}</p> : null}
            </fieldset>

            <div className="flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={busy || !check.matches}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                >
                    {busy ? 'Saving…' : 'Record cheque'}
                </button>
            </div>
        </form>
    );
}

/**
 * /admin/billing/cheques -- "Cheques and balance" (ADR-016 section 8, slice 4.2). What the billing party still owes per cut-off period,
 * the cheques received, and the form to record one against the periods it pays. A cheque never touches a shift or the cash drawer.
 */
export default function ChequesPage() {
    const { setUser } = useAuth();
    const [balance, setBalance] = useState(null);
    const [cheques, setCheques] = useState(null);
    const [page, setPage] = useState(1);
    const [statusFilter, setStatusFilter] = useState('');
    const [asOf, setAsOf] = useState('');
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [serverErrors, setServerErrors] = useState({});
    const [formFailure, setFormFailure] = useState(null);
    const [returning, setReturning] = useState(null);
    const [returnBusy, setReturnBusy] = useState(false);
    const [returnError, setReturnError] = useState(null);
    const [writeOffs, setWriteOffs] = useState(null);
    const [writeOffPage, setWriteOffPage] = useState(1);
    const [writingOff, setWritingOff] = useState(null); // { period, chequeId }
    const [writeOffBusy, setWriteOffBusy] = useState(false);
    const [writeOffError, setWriteOffError] = useState(null);
    const [reversing, setReversing] = useState(null);
    const [reverseBusy, setReverseBusy] = useState(false);
    const [reverseError, setReverseError] = useState(null);
    const [followUp, setFollowUp] = useState(null); // the cheque just marked returned: offer to write the amount off
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(() => {
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        const instant = asOfInstant(asOf);
        const balancePath = `/api/v1/billing/balance${instant ? `?as_of=${encodeURIComponent(instant)}` : ''}`;
        const chequePath = `/api/v1/billing-cheques?per_page=${PER_PAGE}&page=${page}${statusFilter ? `&status=${statusFilter}` : ''}`;
        const writeOffPath = `/api/v1/billing-write-offs?per_page=${PER_PAGE}&page=${writeOffPage}`;
        Promise.all([request(balancePath), request(chequePath), request(writeOffPath)]).then(([balanceResponse, chequeResponse, writeOffResponse]) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (!balanceResponse.ok) {
                setFailure(balanceResponse);
            } else if (!chequeResponse.ok) {
                setFailure(chequeResponse);
            } else if (!writeOffResponse.ok) {
                setFailure(writeOffResponse);
            }
            setBalance(balanceResponse.ok ? balanceResponse.body.data : null);
            setCheques(chequeResponse.ok ? chequeResponse.body : null);
            setWriteOffs(writeOffResponse.ok ? writeOffResponse.body : null);
        });
    }, [asOf, page, statusFilter, writeOffPage]);

    useEffect(() => {
        load();
    }, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    async function record(payload) {
        setSaving(true);
        setServerErrors({});
        setFormFailure(null);
        const response = await request('/api/v1/billing-cheques', { method: 'POST', body: payload });
        setSaving(false);
        if (response.ok) {
            setShowForm(false);
            setToast(`Cheque ${response.body.data.cheque_number} recorded`);
            setPage(1);
            load();
            return;
        }
        setServerErrors(fieldErrors(response));
        setFormFailure(response.body?.error?.message ?? failureMessage(response, 'The cheque could not be recorded.'));
        if (response.status === 409) {
            load(); // what is owed changed under the user: show the new figures
        }
    }

    async function markReturned(input) {
        setReturnBusy(true);
        setReturnError(null);
        const response = await request(`/api/v1/billing-cheques/${returning.id}/return`, { method: 'POST', body: input });
        setReturnBusy(false);
        if (response.ok) {
            setToast(`Cheque ${returning.cheque_number} marked returned`);
            setFollowUp(response.body.data);
            setReturning(null);
            load();
            return;
        }
        const detail = Object.values(fieldErrors(response))[0];
        setReturnError(detail ?? response.body?.error?.message ?? failureMessage(response, 'The cheque could not be marked returned.'));
        if (response.status === 409) {
            load();
        }
    }

    async function writeOff(payload) {
        setWriteOffBusy(true);
        setWriteOffError(null);
        const response = await request('/api/v1/billing-write-offs', { method: 'POST', body: payload });
        setWriteOffBusy(false);
        if (response.ok) {
            setToast(`${formatMoney(response.body.data.amount)} written off for ${response.body.data.period_label}`);
            setWritingOff(null);
            setFollowUp(null);
            setWriteOffPage(1);
            load();
            return;
        }
        const detail = Object.values(fieldErrors(response))[0];
        setWriteOffError(detail ?? response.body?.error?.message ?? failureMessage(response, 'The amount could not be written off.'));
        if (response.status === 409) {
            load(); // what is owed changed under the user: show the new figures
        }
    }

    async function reverseWriteOff(input) {
        setReverseBusy(true);
        setReverseError(null);
        const response = await request(`/api/v1/billing-write-offs/${reversing.id}/reverse`, { method: 'POST', body: input });
        setReverseBusy(false);
        if (response.ok) {
            setToast(`Write-off of ${formatMoney(reversing.amount)} reversed`);
            setReversing(null);
            load();
            return;
        }
        const detail = Object.values(fieldErrors(response))[0];
        setReverseError(detail ?? response.body?.error?.message ?? failureMessage(response, 'The write-off could not be reversed.'));
        if (response.status === 409) {
            load();
        }
    }

    function openWriteOff(period, chequeId = '') {
        setWriteOffError(null);
        setWritingOff({ period, chequeId });
    }

    async function exportCsv(path, filename) {
        const outcome = await downloadCsv(path, filename);
        if (outcome.ok) {
            setToast(`Exported ${outcome.filename}`);
        } else {
            setFailure({ status: outcome.status ?? 0, body: null, message: outcome.message });
        }
    }

    const totals = balance?.totals;
    const failureText = failure ? (failure.message ?? failureMessage(failure, 'The balance could not be loaded.')) : null;
    const chequeRows = cheques?.data ?? [];
    const writeOffRows = writeOffs?.data ?? [];
    const followUpPeriods = followUp ? (balance?.periods ?? []).filter((period) => canWriteOff(period) && followUp.allocations.some((allocation) => allocation.period_start === period.period_start)) : [];
    const button = 'min-h-11 text-emerald-400 hover:underline disabled:opacity-50 lg:min-h-0';
    const link = 'flex min-h-11 items-center self-start rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0';

    return (
        <AdminLayout title="Cheques and balance" requiredCapability="BILLING_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            {returning && (
                <ReturnDialog cheque={returning} busy={returnBusy} error={returnError} onConfirm={markReturned} onCancel={() => (returnBusy ? null : setReturning(null))} />
            )}

            {writingOff && (
                <WriteOffDialog
                    period={writingOff.period}
                    initialChequeId={writingOff.chequeId}
                    busy={writeOffBusy}
                    error={writeOffError}
                    onConfirm={writeOff}
                    onCancel={() => (writeOffBusy ? null : setWritingOff(null))}
                />
            )}
            {reversing && (
                <ReverseWriteOffDialog row={reversing} busy={reverseBusy} error={reverseError} onConfirm={reverseWriteOff} onCancel={() => (reverseBusy ? null : setReversing(null))} />
            )}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Cheques and balance</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        What {balance?.party?.name ?? 'TSC'} still owes for each cut-off, and the cheques received. A cheque pays the periods you choose; a cheque that bounces is marked returned and the
                        amount is owed again. If {balance?.party?.name ?? 'TSC'} will never pay an amount, write it off with a reason; that can be reversed if it was a mistake.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link to="/admin/billing" className={link}>
                        Statement
                    </Link>
                    <Link to="/admin/billing/runs" className={link}>
                        Billing runs
                    </Link>
                    <button
                        type="button"
                        onClick={() => {
                            setShowForm(true);
                            setServerErrors({});
                            setFormFailure(null);
                        }}
                        disabled={showForm}
                        className="min-h-11 self-start rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                    >
                        Record cheque
                    </button>
                </div>
            </div>

            {failureText && <ErrorAlert message={failureText} onSignIn={failure.status === 401 ? signIn : undefined} onRetry={failure.status === 401 ? undefined : load} />}

            {followUp && (
                <div role="status" className="mb-4 rounded-lg border border-amber-700 bg-amber-950/40 p-3 text-sm text-amber-200">
                    <p>
                        Cheque {followUp.cheque_number} is marked returned. The owed amount is back. Write it off?
                    </p>
                    <p className="mt-1 text-xs text-amber-300/80">Only write it off if {balance?.party?.name ?? 'TSC'} will never pay it. If the cheque will be paid later, leave it owed.</p>
                    <div className="mt-2 flex flex-wrap gap-3">
                        {followUpPeriods.map((period) => (
                            <button key={period.period_start} type="button" className="min-h-11 text-violet-300 hover:underline lg:min-h-0" onClick={() => openWriteOff(period, followUp.id)}>
                                Write off part of {period.period_label}
                            </button>
                        ))}
                        <button type="button" className="min-h-11 text-slate-300 hover:underline lg:min-h-0" onClick={() => setFollowUp(null)}>
                            Not now
                        </button>
                    </div>
                </div>
            )}

            {showForm && balance && (
                <>
                    {formFailure && (
                        <p role="alert" className="mb-3 rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-rose-300">
                            {formFailure}
                        </p>
                    )}
                    <ChequeForm periods={balance.periods} busy={saving} serverErrors={serverErrors} onSubmit={record} onCancel={() => setShowForm(false)} />
                </>
            )}

            {totals && (
                <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <Tile label="Billed" value={formatMoney(totals.billed_amount)} hint="From the credit ledger, after voids and refunds" />
                    <Tile label="Received" value={formatMoney(totals.received_amount)} hint="Cheques that have not bounced" />
                    <Tile label="Written off" value={formatMoney(totals.written_off_amount)} tone={totals.written_off_amount === '0.00' ? 'text-slate-100' : 'text-violet-300'} hint="Bad debt, not money received" />
                    <Tile label="Still owed" value={formatMoney(totals.owed_amount)} tone="text-amber-300" hint={`${totals.open_period_count} open period${totals.open_period_count === 1 ? '' : 's'}`} />
                    <Tile label="Credit to TSC" value={formatMoney(totals.credit_amount)} tone={totals.credit_amount === '0.00' ? 'text-slate-100' : 'text-rose-300'} hint="Paid more than now billed" />
                    <Tile label="Bounced" value={formatMoney(totals.returned_cheques_amount)} hint="Returned cheques, owed again" />
                </div>
            )}

            <div className="mb-2 flex flex-wrap items-end justify-between gap-3">
                <h3 className="text-base font-semibold text-slate-100">Owed by cut-off period</h3>
                <div className="flex flex-wrap items-end gap-3">
                    <label className={LABEL}>
                        As of (optional)
                        <input type="datetime-local" className={`${FIELD} w-56`} value={asOf} onChange={(event) => setAsOf(event.target.value)} />
                    </label>
                    <button type="button" className={button} onClick={() => exportCsv('/api/v1/billing/balance', 'billing_balance.csv')}>
                        Export balance CSV
                    </button>
                </div>
            </div>
            <div className="mb-6 overflow-x-auto rounded-lg border border-slate-800 bg-slate-900">
                <table className="w-full min-w-[60rem] text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-500">
                        <tr>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Period</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Billed</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Received</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Written off</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Owed</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Status</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Cheques</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {(balance?.periods ?? []).map((period) => {
                            const credit = period.owed_amount.startsWith('-');
                            return (
                                <tr key={period.period_start} className="border-t border-slate-800 align-top text-slate-200">
                                    <td className="px-3 py-2">
                                        {period.period_label}
                                        <span className="block font-mono text-xs text-slate-500">
                                            {period.period_start} to {period.period_end}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-right font-mono tabular-nums">{formatMoney(period.billed_amount)}</td>
                                    <td className="px-3 py-2 text-right font-mono tabular-nums">{formatMoney(period.received_amount)}</td>
                                    <td className={`px-3 py-2 text-right font-mono tabular-nums ${period.written_off_amount === '0.00' ? '' : 'text-violet-300'}`}>{formatMoney(period.written_off_amount)}</td>
                                    <td className={`px-3 py-2 text-right font-mono tabular-nums ${credit ? 'text-rose-300' : ''}`}>
                                        {period.status === 'OVER_WRITTEN_OFF' ? formatMoney('0.00') : credit ? `${formatMoney(period.credit_amount)} credit` : formatMoney(period.owed_amount)}
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={period.status} />
                                        {period.written_off_amount !== '0.00' && period.status !== 'WRITTEN_OFF' && period.status !== 'OVER_WRITTEN_OFF' && (
                                            <span className="mt-1 block text-xs text-violet-300">{formatMoney(period.written_off_amount)} written off</span>
                                        )}
                                        {period.write_off_excess_amount !== '0.00' && (
                                            <span className="mt-1 block text-xs text-rose-300">
                                                {formatMoney(period.write_off_excess_amount)} more was written off than is now unpaid (a refund came after). Reverse the write-off.
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {period.cheques.map((cheque) => (
                                            <span key={cheque.cheque_id} className={`block ${cheque.status === 'RETURNED' ? 'text-rose-300 line-through' : 'text-slate-300'}`}>
                                                {cheque.bank} {cheque.cheque_number}: {formatMoney(cheque.amount)}
                                                {cheque.status === 'RETURNED' ? ' (returned)' : ''}
                                            </span>
                                        ))}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-right text-xs">
                                        {canWriteOff(period) && !asOf && (
                                            <button type="button" className="min-h-11 text-violet-300 hover:underline lg:min-h-0" aria-label={`Write off an amount of ${period.period_label}`} onClick={() => openWriteOff(period)}>
                                                Write off
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        {!loading && (balance?.periods ?? []).length === 0 && !failure && (
                            <tr>
                                <td colSpan={8} className="px-3 py-6 text-center text-slate-400">
                                    Nothing has been charged to employee credit yet, so nothing is owed.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mb-2 flex flex-wrap items-end justify-between gap-3">
                <h3 className="text-base font-semibold text-slate-100">Cheques received</h3>
                <div className="flex flex-wrap items-end gap-3">
                    <label className={LABEL}>
                        Show
                        <select
                            className={`${FIELD} w-40`}
                            value={statusFilter}
                            onChange={(event) => {
                                setPage(1);
                                setStatusFilter(event.target.value);
                            }}
                        >
                            <option value="">All cheques</option>
                            <option value="RECEIVED">Received</option>
                            <option value="RETURNED">Returned</option>
                        </select>
                    </label>
                    <button type="button" className={button} onClick={() => exportCsv(`/api/v1/billing-cheques${statusFilter ? `?status=${statusFilter}` : ''}`, 'billing_cheques.csv')}>
                        Export cheques CSV
                    </button>
                </div>
            </div>
            <div className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-500">
                        <tr>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Received</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Cheque</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Amount</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Pays</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Status</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {chequeRows.map((cheque) => (
                            <tr key={cheque.id} className="border-t border-slate-800 align-top text-slate-200">
                                <td className="px-3 py-2 text-xs">
                                    {cheque.received_on}
                                    <span className="block text-slate-500">{cheque.received_by?.name ?? ''}</span>
                                </td>
                                <td className="px-3 py-2">
                                    <span className="font-mono text-emerald-300">{cheque.cheque_number}</span> · {cheque.bank}
                                    <span className="block text-xs text-slate-500">
                                        dated {cheque.cheque_date}
                                        {cheque.notes ? ` · ${cheque.notes}` : ''}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right font-mono tabular-nums">{formatMoney(cheque.amount)}</td>
                                <td className="px-3 py-2 text-xs">
                                    {cheque.allocations.map((allocation) => (
                                        <span key={allocation.period_start} className="block">
                                            {allocation.period_start} to {allocation.period_end}: {formatMoney(allocation.amount)}
                                        </span>
                                    ))}
                                </td>
                                <td className="px-3 py-2">
                                    <ChequeStatus status={cheque.status} />
                                    {cheque.status === 'RETURNED' && (
                                        <span className="mt-1 block text-xs text-slate-400">
                                            {cheque.returned_on}: {cheque.return_reason}
                                        </span>
                                    )}
                                </td>
                                <td className="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    {cheque.status === 'RECEIVED' && (
                                        <button
                                            type="button"
                                            className="min-h-11 text-rose-400 hover:underline lg:min-h-0"
                                            aria-label={`Mark cheque ${cheque.cheque_number} returned`}
                                            onClick={() => {
                                                setReturnError(null);
                                                setReturning(cheque);
                                            }}
                                        >
                                            Mark returned
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {!loading && chequeRows.length === 0 && !failure && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-slate-400">
                                    No cheques have been recorded yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pager meta={cheques?.meta} onPage={setPage} />

            <div className="mb-2 mt-8 flex flex-wrap items-end justify-between gap-3">
                <h3 className="text-base font-semibold text-slate-100">Write-offs</h3>
            </div>
            <p className="mb-2 max-w-2xl text-xs text-slate-500">Amounts taken off what is owed because they will not be paid. Nothing here is ever edited or deleted; a mistake is cancelled with a reversal, which stays in the list.</p>
            <div className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-500">
                        <tr>
                            <th scope="col" className="px-3 py-2 text-left font-normal">When and who</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Period</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Amount</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Reason</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Status</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {writeOffRows.map((row) => (
                            <tr key={row.id} className="border-t border-slate-800 align-top text-slate-200">
                                <td className="px-3 py-2 text-xs">
                                    {row.written_at.slice(0, 16).replace('T', ' ')}
                                    <span className="block text-slate-500">{row.written_by?.name ?? ''}</span>
                                </td>
                                <td className="px-3 py-2">{row.period_label}</td>
                                <td className={`px-3 py-2 text-right font-mono tabular-nums ${row.kind === 'REVERSAL' ? 'text-slate-400' : 'text-violet-300'}`}>
                                    {row.kind === 'REVERSAL' ? `−${formatMoney(row.amount)}` : formatMoney(row.amount)}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {row.reason}
                                    {row.cheque && <span className="block text-slate-500">Cheque {row.cheque.bank} {row.cheque.cheque_number}</span>}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    <span className={row.status === 'REVERSED' || row.kind === 'REVERSAL' ? 'text-slate-400' : 'text-violet-300'}>{writeOffStatusLabel(row)}</span>
                                    {row.reversal && (
                                        <span className="block text-slate-500">
                                            {row.reversal.reversed_by?.name ?? ''}: {row.reversal.reason}
                                        </span>
                                    )}
                                </td>
                                <td className="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    {row.kind === 'WRITE_OFF' && row.status === 'ACTIVE' && (
                                        <button
                                            type="button"
                                            className="min-h-11 text-rose-400 hover:underline lg:min-h-0"
                                            aria-label={`Reverse the write-off of ${formatMoney(row.amount)} for ${row.period_label}`}
                                            onClick={() => {
                                                setReverseError(null);
                                                setReversing(row);
                                            }}
                                        >
                                            Reverse
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {!loading && writeOffRows.length === 0 && !failure && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-slate-400">
                                    Nothing has been written off.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pager meta={writeOffs?.meta} onPage={setWriteOffPage} />
        </AdminLayout>
    );
}

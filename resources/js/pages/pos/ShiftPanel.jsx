import { pesos, toCents } from './posMoney';

/**
 * Everything about the open shift that is not selling: the cash drawer (paid in and paid out, each with a reason) and
 * closing the shift. It used to sit beside the cart on the selling screen, taking a quarter of it for something done a few
 * times a day.
 */
export default function ShiftPanel({ shift, type, amount, reason, notice, busy, onType, onAmount, onReason, onRecord, onCloseShift, children }) {
    const opening = toCents(shift?.opening_cash);

    return (
        <div className="mx-auto grid max-w-4xl grid-cols-1 gap-4 p-4 md:grid-cols-2">
            <form onSubmit={onRecord} className="space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-4">
                <h2 className="text-sm font-semibold text-slate-100">Cash drawer</h2>
                <p className="text-xs text-slate-400">Record money put into the drawer or taken out of it, with the reason.</p>
                {notice && (
                    <p role="status" className="rounded bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">
                        {notice}
                    </p>
                )}
                <div>
                    <label htmlFor="cash_movement_type" className="mb-1 block text-xs text-slate-400">
                        What happened
                    </label>
                    <select
                        id="cash_movement_type"
                        value={type}
                        onChange={(event) => onType(event.target.value)}
                        className="min-h-12 w-full rounded border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100"
                    >
                        <option value="CASH_IN">Cash in (put into the drawer)</option>
                        <option value="CASH_OUT">Cash out (taken from the drawer)</option>
                    </select>
                </div>
                <div>
                    <label htmlFor="cash_movement_amount" className="mb-1 block text-xs text-slate-400">
                        Amount (₱)
                    </label>
                    <input
                        id="cash_movement_amount"
                        type="text"
                        inputMode="decimal"
                        placeholder="0.00"
                        value={amount}
                        onChange={(event) => onAmount(event.target.value)}
                        className="min-h-12 w-full rounded border border-slate-700 bg-slate-950 px-3 text-right font-mono text-base tabular-nums text-slate-100"
                    />
                </div>
                <div>
                    <label htmlFor="cash_movement_reason" className="mb-1 block text-xs text-slate-400">
                        Reason
                    </label>
                    <input
                        id="cash_movement_reason"
                        type="text"
                        value={reason}
                        onChange={(event) => onReason(event.target.value)}
                        className="min-h-12 w-full rounded border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100"
                    />
                </div>
                <button
                    type="submit"
                    disabled={busy || !amount || !reason}
                    className="min-h-12 w-full rounded bg-emerald-500 px-4 text-sm font-bold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-400"
                >
                    {busy ? 'Recording…' : 'Record'}
                </button>
            </form>

            <section className="space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-4">
                <h2 className="text-sm font-semibold text-slate-100">This shift</h2>
                <dl className="space-y-1 text-sm">
                    <div className="flex justify-between">
                        <dt className="text-slate-400">Opening cash</dt>
                        <dd className="font-mono tabular-nums">{opening === null ? '—' : `₱${pesos(opening)}`}</dd>
                    </div>
                </dl>
                <p className="text-xs text-slate-400">
                    When you close the shift you count the cash in the drawer. The system works out what it should be and any variance afterwards; it is never shown to you beforehand.
                </p>
                <button
                    type="button"
                    onClick={onCloseShift}
                    className="min-h-12 w-full rounded border border-slate-700 bg-slate-900 px-4 text-sm font-bold text-slate-100 hover:bg-slate-800"
                >
                    Close shift
                </button>
            </section>

            {children}
        </div>
    );
}

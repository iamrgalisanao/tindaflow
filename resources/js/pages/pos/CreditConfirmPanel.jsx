import { useState } from 'react';
import OverrideApproval from './OverrideApproval';
import { money, periodText, remainingAfter, typeLabel } from './credit';
import { lineCents, pesos } from './posMoney';

/**
 * The payment step of a credit sale: no tender, no keypad, no change. It shows what is being charged and to whom, and one button
 * confirms it. A sale the server refuses for the limit shows the figures and three ways on: remove items, take cash instead, or (ADR-016
 * section 6, slice 3.3) ask a manager to approve THIS sale -- the manager is picked by name and types their own code, and the till then
 * sends the sale again with the single-use approval (`grant`, held by the register for this sale only; `onApproved` hands it up).
 */
export default function CreditConfirmPanel({ cart, totalCents, payer, busy, refusal, grant = null, onApproved, onBack, onUseCash, onComplete }) {
    const { employee } = payer;
    const after = remainingAfter(employee.credit, totalCents);
    const [asking, setAsking] = useState(false);

    return (
        <form onSubmit={onComplete} aria-label="Charge to employee" className="mx-auto grid max-w-3xl grid-cols-1 gap-4 p-4">
            <section aria-label="Order summary" className="rounded-lg border border-slate-700 bg-slate-900">
                <div className="flex items-baseline justify-between border-b border-slate-800 px-4 py-3">
                    <h2 className="text-sm font-semibold text-slate-100">Order summary</h2>
                    <span className="font-mono text-xs text-slate-400">
                        {cart.length} {cart.length === 1 ? 'line' : 'lines'}
                    </span>
                </div>
                <ul className="max-h-64 divide-y divide-slate-800 overflow-y-auto">
                    {cart.map((line) => {
                        const cents = lineCents(line.product.selling_price, line.quantity);
                        return (
                            <li key={line.key} className="flex justify-between gap-3 px-4 py-2 text-sm">
                                <span className="min-w-0 break-words text-slate-200">
                                    {line.quantity} &times; {line.product.name}
                                </span>
                                <span className="font-mono tabular-nums text-slate-300">{cents === null ? '—' : `₱${pesos(cents)}`}</span>
                            </li>
                        );
                    })}
                </ul>
                <div className="flex items-center justify-between border-t border-slate-800 px-4 py-3">
                    <span className="font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">Total amount due</span>
                    <span aria-label="Total to charge" className="font-mono text-3xl font-bold tabular-nums text-emerald-400">
                        ₱{pesos(totalCents)}
                    </span>
                </div>
            </section>

            <section aria-label="Employee to charge" className="rounded-lg border border-sky-700/60 bg-slate-900 p-4">
                <p className="text-lg font-semibold text-slate-100">{employee.name}</p>
                <p className="font-mono text-xs text-slate-400">
                    {employee.code} &middot; {typeLabel(employee.type)} &middot; {periodText(employee.credit.periodStart, employee.credit.periodEnd)}
                </p>
                <dl className="mt-3 grid grid-cols-3 gap-2 text-center">
                    {[
                        ['Remaining now', money(employee.credit.remaining), 'text-slate-100'],
                        ['This sale', `₱${pesos(totalCents)}`, 'text-slate-100'],
                        ['Left after', after === null ? '—' : `${after < 0n ? '-' : ''}₱${pesos(after < 0n ? -after : after)}`, after !== null && after < 0n ? 'text-red-400' : 'text-emerald-400'],
                    ].map(([label, value, tone]) => (
                        <div key={label} className="rounded bg-slate-950 px-2 py-2">
                            <dt className="text-[11px] uppercase tracking-wide text-slate-400">{label}</dt>
                            <dd className={`font-mono text-base font-bold tabular-nums ${tone}`}>{value}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            {refusal && (
                <section role="alert" aria-label="Credit limit exceeded" className="rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-200">
                    <p className="font-semibold">This sale is over {employee.name}&apos;s credit limit, so it was not charged.</p>
                    <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 font-mono text-xs sm:grid-cols-4">
                        {[
                            ['Limit', refusal.limit],
                            ['Used', refusal.used],
                            ['Remaining', refusal.remaining],
                            ['This sale', refusal.requested],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-red-300/80">{label}</dt>
                                <dd className="text-sm font-bold text-red-100">{money(value)}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-2 text-xs text-red-300/90">
                        Period {periodText(refusal.periodStart, refusal.periodEnd)}. Remove items to bring the sale within what is left, take cash instead, or ask a manager to approve this sale.
                    </p>
                    {!asking && onApproved && (
                        <button type="button" onClick={() => setAsking(true)} disabled={busy} className="mt-3 min-h-12 rounded-lg border border-amber-400 px-4 text-sm font-bold text-amber-200 hover:bg-amber-400/10 disabled:opacity-50">
                            Ask a manager to approve
                        </button>
                    )}
                    {asking && (
                        <OverrideApproval
                            employee={employee}
                            amount={refusal.requested}
                            busy={busy}
                            onApproved={onApproved}
                            onCancel={() => setAsking(false)}
                        />
                    )}
                </section>
            )}

            {grant && (
                <section aria-label="Manager approval held" className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-100">
                    Approved by <span className="font-semibold">{grant.approverName}</span> for this sale, up to {money(grant.maxAmount)}. Press the button to charge it.
                </section>
            )}

            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={onBack} disabled={busy} className="min-h-16 rounded-lg border border-slate-600 px-5 text-sm font-bold uppercase tracking-wide text-slate-200 hover:bg-slate-800 disabled:opacity-50">
                    Back
                </button>
                {refusal && (
                    <button type="button" onClick={onUseCash} disabled={busy} className="min-h-16 rounded-lg border border-slate-600 px-5 text-sm font-bold uppercase tracking-wide text-slate-200 hover:bg-slate-800 disabled:opacity-50">
                        Pay with cash instead
                    </button>
                )}
                <button
                    type="submit"
                    disabled={busy || totalCents <= 0n}
                    className="min-h-16 flex-1 rounded-lg bg-sky-400 px-4 text-lg font-bold text-slate-950 hover:bg-sky-300 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {busy ? 'Charging…' : `Charge to ${employee.name}`}
                </button>
            </div>
        </form>
    );
}

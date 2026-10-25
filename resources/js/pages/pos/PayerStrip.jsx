import { money, periodText, remainingAfter, remainingCents, typeLabel } from './credit';
import { pesos } from './posMoney';

/**
 * Who the open sale is for, above the register: Cash, or the employee it will be charged to with what they have left. The cashier can
 * switch between the two without losing the cart, and can start over from the first screen (Pos asks first when the cart has items).
 */
export default function PayerStrip({ payer, totalCents, onChangeEmployee, onUseCash, onChargeEmployee, onStartOver }) {
    const buttonClass = 'min-h-11 rounded-md border border-slate-600 px-3 text-xs font-bold uppercase tracking-wide text-slate-200 hover:bg-slate-800';

    if (payer.kind !== 'CREDIT') {
        return (
            <section aria-label="Payer" className="mx-4 mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-900 px-4 py-2">
                <p className="text-sm text-slate-300">
                    Paying with <strong className="text-emerald-400">Cash</strong>
                </p>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={onChargeEmployee} className={buttonClass}>
                        Charge to employee
                    </button>
                    <button type="button" onClick={onStartOver} className={buttonClass}>
                        Start over
                    </button>
                </div>
            </section>
        );
    }

    const { employee } = payer;
    const remaining = remainingCents(employee.credit);
    const after = remainingAfter(employee.credit, totalCents);

    return (
        <section aria-label="Payer" className="mx-4 mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sky-700/70 bg-slate-900 px-4 py-2">
            <div className="min-w-0">
                <p className="truncate text-sm text-slate-300">
                    Charging to <strong className="text-sky-300">{employee.name}</strong>{' '}
                    <span className="font-mono text-xs text-slate-400">
                        {employee.code} &middot; {typeLabel(employee.type)}
                    </span>
                </p>
                <p className="text-xs text-slate-400">
                    Remaining <strong className="font-mono text-slate-100">{remaining === null ? '—' : `₱${pesos(remaining)}`}</strong> of {money(employee.credit.limit)} for{' '}
                    {periodText(employee.credit.periodStart, employee.credit.periodEnd)}
                    {after !== null && totalCents > 0n && (
                        <>
                            {' '}
                            &middot; after this sale <strong className={`font-mono ${after < 0n ? 'text-red-400' : 'text-slate-100'}`}>{`₱${pesos(after)}`}</strong>
                        </>
                    )}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={onChangeEmployee} className={buttonClass}>
                    Change employee
                </button>
                <button type="button" onClick={onUseCash} className={buttonClass}>
                    Pay with cash instead
                </button>
                <button type="button" onClick={onStartOver} className={buttonClass}>
                    Start over
                </button>
            </div>
        </section>
    );
}

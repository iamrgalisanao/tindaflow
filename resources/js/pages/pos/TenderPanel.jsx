import { lineCents, moneyText, pesos, toCents } from './posMoney';

const METHOD_LABELS = { CASH: 'Cash', GCASH: 'GCash', MAYA: 'Maya', CARD: 'Card', OTHER: 'Other' };
const QUICK_CASH = [20, 50, 100, 200, 500, 1000];
const KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '00', '.'];

/**
 * The payment step: what is being charged, how it is being paid, and, for cash, the amount handed over with the change
 * or the balance still owed. All figures are a preview in whole cents; the server checks the payment and works out the
 * change when the sale is finalised. Only cash is entered as an amount; any other method is charged the exact total.
 */
export default function TenderPanel({ cart, totalCents, methods, method, onMethod, amount, onAmount, busy, onBack, onComplete }) {
    const isCash = method === 'CASH';
    const tendered = isCash ? toCents(amount) : totalCents;
    const enough = tendered !== null && tendered >= totalCents;
    const difference = tendered === null ? null : tendered - totalCents;

    // Functional updates, so two quick taps each build on the one before rather than on a stale value.
    function press(key) {
        onAmount((current) => {
            if (key === '.') {
                return current.includes('.') ? current : current === '' ? '0.' : `${current}.`;
            }
            const next = `${current}${key}`;
            return /^\d{0,10}(\.\d{0,2})?$/.test(next) ? next.replace(/^0+(?=\d)/, '') : current;
        });
    }

    function backspace() {
        onAmount((current) => current.slice(0, -1));
    }

    return (
        <form onSubmit={onComplete} className="mx-auto grid max-w-6xl grid-cols-1 gap-4 p-4 lg:grid-cols-2">
            <section aria-label="Order summary" className="flex min-h-0 flex-col rounded-lg border border-slate-700 bg-slate-900">
                <div className="flex items-baseline justify-between border-b border-slate-800 px-4 py-3">
                    <h2 className="text-sm font-semibold text-slate-100">Order summary</h2>
                    <span className="font-mono text-xs text-slate-400">
                        {cart.length} {cart.length === 1 ? 'line' : 'lines'}
                    </span>
                </div>
                <ul className="max-h-[50vh] divide-y divide-slate-800 overflow-y-auto">
                    {cart.map((line) => {
                        const cents = lineCents(line.product.selling_price, line.quantity);
                        return (
                            <li key={line.product.id} className="flex items-start justify-between gap-3 px-4 py-3">
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium text-slate-100">{line.product.name}</span>
                                    <span className="block font-mono text-xs text-slate-400">
                                        {line.quantity} &times; ₱{line.product.selling_price}
                                    </span>
                                </span>
                                <span className="font-mono text-sm font-semibold tabular-nums text-slate-100">{cents === null ? '—' : `₱${pesos(cents)}`}</span>
                            </li>
                        );
                    })}
                </ul>
                <div className="mt-auto border-t border-slate-700 px-4 py-3">
                    <div className="rounded-lg bg-slate-950 p-3 ring-1 ring-slate-800">
                        <div className="flex items-center justify-between gap-3">
                            <span className="font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">Total payable</span>
                            <span className="rounded-md bg-slate-900 px-3 py-1 font-mono text-3xl font-bold tabular-nums text-emerald-400">₱{pesos(totalCents)}</span>
                        </div>
                        <p className="mt-2 text-right text-[11px] text-slate-400">A preview. The server works out the final total when the sale is finalised.</p>
                    </div>
                </div>
            </section>

            <section aria-label="Payment" className="flex flex-col gap-3 rounded-lg border border-slate-700 bg-slate-900 p-4">
                <h2 className="text-sm font-semibold text-slate-100">Payment</h2>

                <div role="radiogroup" aria-label="Payment method" className="grid grid-cols-5 gap-2">
                    {methods.map((option) => (
                        <button
                            key={option}
                            type="button"
                            role="radio"
                            aria-checked={method === option}
                            onClick={() => onMethod(option)}
                            className={`min-h-12 rounded border text-xs font-bold uppercase tracking-wide ${
                                method === option ? 'border-emerald-500 bg-emerald-500 text-slate-950' : 'border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800'
                            }`}
                        >
                            {METHOD_LABELS[option] ?? option}
                        </button>
                    ))}
                </div>

                {isCash ? (
                    <>
                        <div>
                            <label htmlFor="payment_amount" className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-400">
                                Amount tendered
                            </label>
                            <div className="flex items-center rounded border-2 border-slate-700 bg-slate-950 px-3 focus-within:border-emerald-500">
                                <span className="font-mono text-2xl text-slate-400">₱</span>
                                <input
                                    id="payment_amount"
                                    type="text"
                                    inputMode="decimal"
                                    autoFocus
                                    autoComplete="off"
                                    value={amount}
                                    onFocus={(event) => event.target.select()}
                                    onChange={(event) => onAmount(event.target.value)}
                                    aria-invalid={tendered === null}
                                    className="min-h-16 min-w-0 flex-1 bg-transparent px-2 text-right font-mono text-3xl font-bold tabular-nums focus:outline-none"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-4 gap-2 sm:grid-cols-7">
                            <button
                                type="button"
                                onClick={() => onAmount(moneyText(totalCents))}
                                className="col-span-4 min-h-12 rounded border border-emerald-500 bg-emerald-500/10 text-sm font-bold text-emerald-300 hover:bg-emerald-500/20 sm:col-span-1"
                            >
                                Exact
                            </button>
                            {QUICK_CASH.map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => onAmount(`${value}.00`)}
                                    className="min-h-12 rounded border border-slate-700 bg-slate-800 font-mono text-sm font-bold tabular-nums hover:bg-slate-700"
                                >
                                    ₱{value.toLocaleString('en-US')}
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-3 gap-2">
                            {KEYS.map((key) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => press(key)}
                                    className="min-h-14 rounded border border-slate-700 border-b-2 border-b-slate-950 bg-slate-800 font-mono text-xl font-bold hover:bg-slate-700 active:bg-slate-600"
                                >
                                    {key}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={backspace}
                                aria-label="Delete the last digit"
                                className="min-h-14 rounded border border-slate-700 border-b-2 border-b-slate-950 bg-slate-800 font-mono text-xl font-bold hover:bg-slate-700 active:bg-slate-600"
                            >
                                &#9003;
                            </button>
                            <button
                                type="button"
                                onClick={() => onAmount('')}
                                className="col-span-2 min-h-14 rounded border border-slate-700 border-b-2 border-b-slate-950 bg-slate-800 text-sm font-bold uppercase tracking-wide text-slate-300 hover:bg-slate-700 active:bg-slate-600"
                            >
                                Clear
                            </button>
                        </div>
                    </>
                ) : (
                    <p className="rounded bg-slate-950 px-3 py-4 text-sm text-slate-400">
                        {METHOD_LABELS[method] ?? method} is charged the exact total, <span className="font-mono font-semibold">₱{pesos(totalCents)}</span>.
                    </p>
                )}

                <div aria-live="polite" className="rounded border border-slate-700 px-3 py-2">
                    {isCash && tendered === null && <p className="text-sm text-slate-400">Enter the amount handed over.</p>}
                    {difference !== null && difference >= 0n && (
                        <div className="flex items-baseline justify-between text-emerald-400">
                            <span className="text-xs font-bold uppercase tracking-wider">{difference === 0n ? 'Exact amount' : 'Change due'}</span>
                            <span className="font-mono text-2xl font-bold tabular-nums">₱{pesos(difference)}</span>
                        </div>
                    )}
                    {difference !== null && difference < 0n && (
                        <div className="flex items-baseline justify-between text-amber-400">
                            <span className="text-xs font-bold uppercase tracking-wider">Balance remaining</span>
                            <span className="font-mono text-2xl font-bold tabular-nums">₱{pesos(-difference)}</span>
                        </div>
                    )}
                </div>

                <div className="mt-auto flex gap-2">
                    <button type="button" onClick={onBack} className="min-h-16 rounded border border-slate-700 bg-slate-900 px-5 text-sm font-medium hover:bg-slate-800">
                        Back to cart
                    </button>
                    <button
                        type="submit"
                        disabled={busy || !enough}
                        className={`min-h-16 flex-1 rounded-lg px-4 text-lg font-bold disabled:cursor-not-allowed ${
                            enough ? 'bg-emerald-500 text-slate-950 hover:bg-emerald-400' : 'border border-amber-500/40 bg-slate-800 text-amber-400'
                        }`}
                    >
                        {busy ? 'Completing…' : enough ? `Complete sale ₱${pesos(totalCents)}` : difference === null ? 'Enter the amount' : `Short by ₱${pesos(-difference)}`}
                    </button>
                </div>
            </section>
        </form>
    );
}

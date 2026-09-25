import { clampedDiscountCents, fromThousandths, lineCents, pesos, toThousandths } from './posMoney';

const STEP = 1000n; // one whole unit

/**
 * The sale so far, with the price, the quantity, any per-line discount, and the line total of every line, plus the
 * order-level discount and the total the cashier is about to charge. Every figure is a preview computed in whole
 * cents; the server recomputes the sale (and the discount authorization check) when it is finalised. Both discount
 * controls are shown only to a user holding DISCOUNT_OVERRIDE (CheckoutService rejects a non-zero discount, line or
 * order-level, from anyone else) -- a cashier who cannot use them is not shown either.
 */
export default function CartPanel({
    cart,
    subtotalCents,
    discount,
    onDiscountChange,
    onLineDiscountChange,
    canDiscount,
    totalCents,
    hasInvalidLine,
    onQuantity,
    onRemove,
    onCharge,
}) {
    function step(line, direction) {
        const current = toThousandths(line.quantity) ?? STEP;
        const next = current + direction * STEP;
        if (next > 0n) {
            onQuantity(line.product.id, fromThousandths(next));
        }
    }

    const itemCount = cart.length;

    return (
        <section aria-label="Cart" className="flex min-h-0 flex-1 flex-col rounded-lg border border-slate-700 bg-slate-900">
            <div className="flex items-baseline justify-between border-b border-slate-800 px-4 py-3">
                <h2 className="text-sm font-semibold text-slate-100">Cart</h2>
                <span className="rounded bg-slate-800 px-2 py-1 font-mono text-[11px] font-bold uppercase tracking-wider text-emerald-400">
                    {itemCount} {itemCount === 1 ? 'line' : 'lines'}
                </span>
            </div>

            <ul className="min-h-40 flex-1 divide-y divide-slate-800 overflow-y-auto">
                {itemCount === 0 && (
                    <li className="px-4 py-12 text-center text-sm text-slate-400">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="mx-auto mb-3 h-12 w-12 text-emerald-500/60">
                            <path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M8 8v8M12 8v8M16 8v8" />
                        </svg>
                        Nothing in the cart yet.
                        <span className="mt-1 block text-xs text-slate-400">Scan a barcode or tap a product.</span>
                    </li>
                )}
                {cart.map((line) => {
                    const grossCents = lineCents(line.product.selling_price, line.quantity);
                    const invalid = grossCents === null;
                    const lineDiscountCents = invalid || !canDiscount ? 0n : clampedDiscountCents(line.discount, grossCents);
                    const cents = invalid ? null : grossCents - lineDiscountCents;
                    const unit = line.product.unit_of_measure ?? 'unit';
                    return (
                        <li key={line.product.id} className="flex items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-slate-100">{line.product.name}</p>
                                <p className="font-mono text-xs text-slate-400">
                                    @ ₱{line.product.selling_price} / {unit}
                                </p>
                                {canDiscount && (
                                    <div className="mt-1 flex items-center gap-1">
                                        <label htmlFor={`line_discount_${line.product.id}`} className="font-mono text-[10px] font-bold uppercase tracking-wider text-amber-400">
                                            -₱
                                        </label>
                                        <input
                                            id={`line_discount_${line.product.id}`}
                                            type="text"
                                            inputMode="decimal"
                                            placeholder="0.00"
                                            value={line.discount ?? ''}
                                            onFocus={(event) => event.target.select()}
                                            onChange={(event) => onLineDiscountChange(line.product.id, event.target.value)}
                                            aria-label={`Discount on ${line.product.name}`}
                                            className="min-h-7 w-16 rounded border border-slate-700 bg-slate-950 px-1 text-right font-mono text-xs tabular-nums text-amber-300 focus:border-amber-500 focus:outline-none"
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="flex shrink-0 items-center">
                                <button
                                    type="button"
                                    onClick={() => step(line, -1n)}
                                    disabled={(toThousandths(line.quantity) ?? 0n) <= STEP}
                                    aria-label={`One less ${line.product.name}`}
                                    className="min-h-11 min-w-11 rounded-l border border-slate-700 bg-slate-900 text-lg font-semibold text-slate-300 hover:bg-slate-800 disabled:opacity-40"
                                >
                                    &minus;
                                </button>
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    value={line.quantity}
                                    onChange={(event) => onQuantity(line.product.id, event.target.value)}
                                    aria-label={`Quantity of ${line.product.name}`}
                                    aria-invalid={invalid}
                                    className={`min-h-11 w-16 border-y px-1 text-center font-mono text-sm tabular-nums focus:outline-none focus:ring-1 ${
                                        invalid ? 'border-red-500 bg-red-500/10 focus:ring-red-500' : 'border-slate-700 focus:ring-emerald-500'
                                    }`}
                                />
                                <button
                                    type="button"
                                    onClick={() => step(line, 1n)}
                                    aria-label={`One more ${line.product.name}`}
                                    className="min-h-11 min-w-11 rounded-r border border-slate-700 bg-slate-900 text-lg font-semibold text-slate-300 hover:bg-slate-800"
                                >
                                    +
                                </button>
                            </div>

                            <p className="w-24 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-slate-100">
                                {invalid ? '—' : `₱${pesos(cents)}`}
                            </p>

                            <button
                                type="button"
                                onClick={() => onRemove(line.product.id)}
                                aria-label={`Remove ${line.product.name}`}
                                className="min-h-11 min-w-11 shrink-0 rounded text-lg text-slate-400 hover:bg-red-500/10 hover:text-red-400"
                            >
                                &times;
                            </button>
                        </li>
                    );
                })}
            </ul>

            <div className="border-t border-slate-700 px-4 py-3">
                {hasInvalidLine && (
                    <p role="alert" className="mb-2 rounded bg-red-500/10 px-3 py-2 text-xs text-red-300">
                        A quantity is not valid. Use a number above zero, with up to 3 decimals.
                    </p>
                )}
                {canDiscount && (
                    <div className="mb-2 flex items-center justify-between gap-3 rounded border border-slate-800 bg-slate-950 px-3 py-2">
                        <label htmlFor="order_discount" className="font-mono text-[11px] font-bold uppercase tracking-widest text-amber-400">
                            Discount
                        </label>
                        <div className="flex items-center gap-1">
                            <span className="font-mono text-sm text-slate-400">-₱</span>
                            <input
                                id="order_discount"
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={discount}
                                onFocus={(event) => event.target.select()}
                                onChange={(event) => onDiscountChange(event.target.value)}
                                aria-label="Order discount amount"
                                className="min-h-10 w-24 rounded border border-slate-700 bg-slate-900 px-2 text-right font-mono text-sm tabular-nums text-amber-300 focus:border-amber-500 focus:outline-none"
                            />
                        </div>
                    </div>
                )}
                <div className="rounded-lg bg-slate-950 p-3 ring-1 ring-slate-800">
                    {subtotalCents > totalCents && (
                        <div className="mb-1 flex items-center justify-between gap-3 text-xs text-slate-400">
                            <span>Subtotal</span>
                            <span className="font-mono tabular-nums">₱{pesos(subtotalCents)}</span>
                        </div>
                    )}
                    {subtotalCents > totalCents && (
                        <div className="mb-2 flex items-center justify-between gap-3 text-xs text-amber-400">
                            <span>Discount</span>
                            <span className="font-mono tabular-nums">-₱{pesos(subtotalCents - totalCents)}</span>
                        </div>
                    )}
                    <div className="flex items-center justify-between gap-3">
                        <span className="font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">Total amount due</span>
                        <span aria-label="Total to charge" className="rounded-md bg-slate-900 px-3 py-1 font-mono text-3xl font-bold tabular-nums text-emerald-400">
                            ₱{pesos(totalCents)}
                        </span>
                    </div>
                    <p className="mt-2 text-right text-[11px] text-slate-400">A preview. The server works out the final total when the sale is finalised.</p>
                </div>
                <button
                    type="button"
                    disabled={itemCount === 0 || hasInvalidLine}
                    onClick={onCharge}
                    className="mt-3 min-h-16 w-full rounded-lg bg-emerald-500 px-4 text-lg font-bold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-400"
                >
                    {itemCount === 0 ? 'Charge' : `Charge ₱${pesos(totalCents)}`}
                </button>
            </div>
        </section>
    );
}

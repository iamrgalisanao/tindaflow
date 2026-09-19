import { useEffect, useMemo, useRef, useState } from 'react';
import SlideOver from '../SlideOver';
import { fieldErrors, request } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';
import { QUANTITY_PATTERN, fromCents, fromThousandths, refundEventCents, toCents, toThousandths, trimQuantity } from './refundMath';
import { DISPOSITIONS, PAYMENT_METHODS, reversalMessage } from './salesParts';

const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-2 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

const MONEY_PATTERN = /^\d{1,10}\.\d{2}$/;

/**
 * Slide-over for saleRefund. The customer gets back what the sale line was worth, in proportion to the
 * quantity returned -- the server derives every amount from the sale and earlier refunds, and this panel
 * shows the same figure as a preview and pre-fills the settlement with it. Each returned line needs an
 * explicit disposition (nothing defaults to "back on the shelf"), and the money returned must add up to
 * the refund total. Someone who may approve refunds completes it immediately; anyone else sends a
 * request that a manager approves.
 */
export default function RefundPanel({ sale, executesImmediately, onDone, onClose, onUnauthorized }) {
    const [prior, setPrior] = useState(null); // null while loading, else { [saleItemId]: { quantity, amount } }
    const [priorFailed, setPriorFailed] = useState(false);
    const [lines, setLines] = useState({});
    const [settlements, setSettlements] = useState([{ method: 'CASH', amount: '', reference: '', edited: false }]);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const keyRef = useRef(crypto.randomUUID());

    useEffect(() => {
        let cancelled = false;
        const completed = sale.refunds.filter((refund) => refund.status === 'COMPLETED');
        Promise.all(completed.map((refund) => request(`/api/v1/refunds/${refund.id}`))).then((responses) => {
            if (cancelled) {
                return;
            }
            if (responses.some((response) => !response.ok)) {
                setPriorFailed(true);
                return;
            }
            const totals = {};
            responses.forEach((response) =>
                response.body.items.forEach((item) => {
                    const entry = totals[item.sale_item_id] ?? { quantity: 0n, amount: 0n };
                    totals[item.sale_item_id] = { quantity: entry.quantity + toThousandths(item.quantity_returned), amount: entry.amount + toCents(item.unit_refund_amount) };
                }),
            );
            setPrior(totals);
        });
        return () => {
            cancelled = true;
        };
    }, [sale.refunds]);

    const rows = useMemo(
        () =>
            sale.items.map((item) => {
                const earlier = prior?.[item.id] ?? { quantity: 0n, amount: 0n };
                const remaining = toThousandths(item.quantity) - earlier.quantity;
                const line = lines[item.id] ?? { quantity: '', disposition: '' };
                const quantityText = line.quantity.trim();
                const valid = quantityText !== '' && QUANTITY_PATTERN.test(quantityText) && toThousandths(quantityText) > 0n;
                const tooMany = valid && toThousandths(quantityText) > remaining;
                const cents = valid && !tooMany
                    ? refundEventCents({
                          netLineAmount: item.net_line_amount,
                          saleQuantity: item.quantity,
                          priorQuantity: fromThousandths(earlier.quantity),
                          priorAmount: fromCents(earlier.amount),
                          requestedQuantity: quantityText,
                      })
                    : null;
                return { item, line, remaining, quantityText, valid, tooMany, cents };
            }),
        [sale.items, prior, lines],
    );

    const selected = rows.filter((row) => row.quantityText !== '');
    const totalCents = rows.reduce((sum, row) => sum + (row.cents ?? 0n), 0n);
    const settledCents = settlements.reduce((sum, row) => sum + (MONEY_PATTERN.test(row.amount.trim()) ? toCents(row.amount.trim()) : 0n), 0n);
    const singleAuto = settlements.length === 1 && !settlements[0].edited;
    const effectiveSettlements = settlements.map((row) => (singleAuto ? { ...row, amount: totalCents > 0n ? fromCents(totalCents) : '' } : row));
    const effectiveSettledCents = singleAuto ? totalCents : settledCents;

    function touch() {
        keyRef.current = crypto.randomUUID();
        setFormError(null);
    }

    function setLine(itemId, patch) {
        touch();
        setLines((prior_) => ({ ...prior_, [itemId]: { quantity: '', disposition: '', ...prior_[itemId], ...patch } }));
        setErrors({});
    }

    function setSettlement(index, patch) {
        touch();
        setSettlements((current) => current.map((row, i) => (i === index ? { ...row, ...patch, edited: true } : row)));
        setErrors({});
    }

    function validate() {
        const found = {};
        if (selected.length === 0) {
            found.items = 'Choose at least one item to refund and how many.';
        }
        selected.forEach((row) => {
            const key = row.item.id;
            if (!row.valid) {
                found[`quantity_${key}`] = 'Enter a quantity above zero, with up to 3 decimals.';
            } else if (row.tooMany) {
                found[`quantity_${key}`] = `Only ${trimQuantity(fromThousandths(row.remaining))} left to refund on this line.`;
            }
            if (row.line.disposition === '') {
                found[`disposition_${key}`] = 'Say what happens to the returned item.';
            }
        });
        effectiveSettlements.forEach((row, index) => {
            if (selected.length > 0 && (!MONEY_PATTERN.test(row.amount.trim()) || toCents(row.amount.trim()) <= 0n)) {
                found[`settlement_${index}`] = 'Enter an amount such as 100.00.';
            }
        });
        if (Object.keys(found).length === 0 && effectiveSettledCents !== totalCents) {
            found.settlements = `The amounts returned add up to ${formatMoney(fromCents(effectiveSettledCents))} but the refund comes to ${formatMoney(fromCents(totalCents))}.`;
        }
        if (reason.trim() === '') {
            found.reason = 'Say why this is being refunded.';
        }
        return found;
    }

    async function submit(event) {
        event.preventDefault();
        const found = validate();
        if (Object.keys(found).length > 0) {
            setErrors(found);
            return;
        }
        setSaving(true);
        setFormError(null);
        const result = await request(`/api/v1/sales/${sale.id}/refunds`, {
            method: 'POST',
            body: {
                items: selected.map((row) => ({ sale_item_id: row.item.id, quantity: row.quantityText, disposition: row.line.disposition })),
                settlements: effectiveSettlements.map((row) => ({
                    payment_method: row.method,
                    amount: row.amount.trim(),
                    ...(row.reference.trim() !== '' ? { external_reference: row.reference.trim() } : {}),
                })),
                reason: reason.trim(),
            },
            headers: { 'Idempotency-Key': keyRef.current },
        });
        setSaving(false);
        if (result.ok) {
            onDone(result.body);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        const code = result.body?.error?.code;
        if (code === 'REFUND_SETTLEMENT_MISMATCH') {
            const derived = result.body.error.details?.refund_total;
            setErrors({ settlements: `The refund comes to ${derived ? formatMoney(derived) : 'a different amount'}. Change the amounts returned to match.` });
            return;
        }
        const serverErrors = fieldErrors(result);
        if (Object.keys(serverErrors).length > 0) {
            setErrors({ server: Object.values(serverErrors)[0] });
            return;
        }
        if (result.status === 0) {
            setFormError('The connection dropped, so this may not have been recorded. Press the button again — it will not be applied twice.');
            return;
        }
        keyRef.current = crypto.randomUUID();
        setFormError(reversalMessage(result, 'The refund could not be recorded.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <div>
                <p className="font-mono text-[11px] text-slate-500">Refund total</p>
                <p className="font-mono text-base tabular-nums text-slate-100">{formatMoney(fromCents(totalCents))}</p>
            </div>
            <div className="flex items-center gap-2">
                <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                    Cancel
                </button>
                <button
                    type="submit"
                    form="refund_form"
                    disabled={saving || prior === null}
                    className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                >
                    {saving ? 'Working…' : executesImmediately ? 'Refund' : 'Request refund'}
                </button>
            </div>
        </div>
    );

    return (
        <SlideOver titleId="refund_panel_title" title="Refund" badge={sale.invoice_number ? `#${sale.invoice_number}` : null} onClose={onClose} footer={footer}>
            <form id="refund_form" onSubmit={submit} noValidate className="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                {(formError || errors.server) && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError ?? errors.server}
                    </p>
                )}
                {priorFailed && (
                    <p role="alert" className="rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-100">
                        Earlier refunds on this sale could not be loaded, so quantities left cannot be shown. Close this and try again.
                    </p>
                )}

                <p className="text-xs text-slate-400">
                    {executesImmediately
                        ? 'You can approve refunds, so this takes effect as soon as you confirm. It is recorded against your open shift on this terminal.'
                        : 'A manager has to approve this before any money or stock moves. You will find it under Approvals once it is decided.'}
                </p>

                <fieldset>
                    <legend className="mb-2 text-xs text-slate-400">
                        Items being returned <span className="text-emerald-400">*</span>
                    </legend>
                    {errors.items && (
                        <p role="alert" className="mb-2 font-mono text-[11px] text-rose-400">
                            &#9888; {errors.items}
                        </p>
                    )}
                    <ul className="space-y-3">
                        {rows.map((row) => {
                            const key = row.item.id;
                            const done = prior !== null && row.remaining <= 0n;
                            return (
                                <li key={key} className={`rounded-md border p-3 ${done ? 'border-slate-800 opacity-60' : 'border-slate-700 bg-slate-950/40'}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="break-words text-sm text-slate-100">{row.item.product_name_snapshot}</p>
                                            <p className="font-mono text-[11px] text-slate-500">
                                                {trimQuantity(row.item.quantity)} &times; {formatMoney(row.item.unit_price_snapshot)} = {formatMoney(row.item.net_line_amount)}
                                            </p>
                                        </div>
                                        <p className="shrink-0 font-mono text-[11px] text-slate-400">{prior === null ? '…' : done ? 'Fully refunded' : `${trimQuantity(fromThousandths(row.remaining))} left`}</p>
                                    </div>
                                    {!done && (
                                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <label htmlFor={`refund_qty_${key}`} className="mb-1 block text-[11px] text-slate-500">
                                                    Quantity returned
                                                </label>
                                                <input
                                                    id={`refund_qty_${key}`}
                                                    type="text"
                                                    inputMode="decimal"
                                                    autoComplete="off"
                                                    disabled={prior === null}
                                                    value={row.line.quantity}
                                                    aria-invalid={Boolean(errors[`quantity_${key}`])}
                                                    onChange={(event) => setLine(key, { quantity: event.target.value })}
                                                    className={`${inputClass(errors[`quantity_${key}`])} font-mono`}
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor={`refund_disposition_${key}`} className="mb-1 block text-[11px] text-slate-500">
                                                    What happens to it
                                                </label>
                                                <select
                                                    id={`refund_disposition_${key}`}
                                                    value={row.line.disposition}
                                                    aria-invalid={Boolean(errors[`disposition_${key}`])}
                                                    onChange={(event) => setLine(key, { disposition: event.target.value })}
                                                    className={inputClass(errors[`disposition_${key}`])}
                                                >
                                                    <option value="">Choose…</option>
                                                    {DISPOSITIONS.map((disposition) => (
                                                        <option key={disposition.id} value={disposition.id}>
                                                            {disposition.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                    )}
                                    {(errors[`quantity_${key}`] || errors[`disposition_${key}`]) && (
                                        <p role="alert" className="mt-2 font-mono text-[11px] text-rose-400">
                                            &#9888; {errors[`quantity_${key}`] ?? errors[`disposition_${key}`]}
                                        </p>
                                    )}
                                    {row.cents !== null && (
                                        <p className="mt-2 font-mono text-[11px] text-emerald-400">Refunds {formatMoney(fromCents(row.cents))} for this line</p>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </fieldset>

                <fieldset>
                    <legend className="mb-2 text-xs text-slate-400">
                        Money returned to the customer <span className="text-emerald-400">*</span>
                    </legend>
                    <div className="space-y-2">
                        {effectiveSettlements.map((row, index) => (
                            <div key={index} className="grid grid-cols-[7rem_1fr] gap-2 sm:grid-cols-[7rem_1fr_1fr_auto]">
                                <select aria-label="Method" value={row.method} onChange={(event) => setSettlement(index, { method: event.target.value })} className={inputClass(false)}>
                                    {PAYMENT_METHODS.map((method) => (
                                        <option key={method} value={method}>
                                            {method}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    aria-label="Amount"
                                    type="text"
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder="0.00"
                                    value={row.amount}
                                    aria-invalid={Boolean(errors[`settlement_${index}`])}
                                    onChange={(event) => setSettlement(index, { amount: event.target.value })}
                                    className={`${inputClass(errors[`settlement_${index}`])} font-mono`}
                                />
                                <input
                                    aria-label="Reference"
                                    type="text"
                                    autoComplete="off"
                                    placeholder="Reference (optional)"
                                    maxLength={255}
                                    value={row.reference}
                                    onChange={(event) => setSettlement(index, { reference: event.target.value })}
                                    className={`${inputClass(false)} col-span-2 sm:col-span-1`}
                                />
                                {settlements.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            touch();
                                            setSettlements((current) => current.filter((_, i) => i !== index));
                                        }}
                                        className="col-span-2 min-h-11 text-xs text-slate-400 hover:text-rose-400 sm:col-span-1 lg:min-h-0"
                                    >
                                        Remove
                                    </button>
                                )}
                                {errors[`settlement_${index}`] && (
                                    <p role="alert" className="col-span-full font-mono text-[11px] text-rose-400">
                                        &#9888; {errors[`settlement_${index}`]}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            touch();
                            setSettlements((current) => [
                                ...current.map((row) => ({ ...row, amount: singleAuto ? fromCents(totalCents) : row.amount, edited: true })),
                                { method: 'GCASH', amount: '', reference: '', edited: true },
                            ]);
                        }}
                        className="mt-2 min-h-11 text-xs text-emerald-400 hover:underline lg:min-h-0"
                    >
                        + Split across another method
                    </button>
                    {errors.settlements ? (
                        <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                            &#9888; {errors.settlements}
                        </p>
                    ) : (
                        <p className="mt-1 text-[11px] text-slate-500">Cash comes out of the drawer; other methods do not.</p>
                    )}
                </fieldset>

                <div>
                    <label htmlFor="refund_reason" className="mb-1 block text-xs text-slate-400">
                        Reason <span className="text-emerald-400">*</span>
                    </label>
                    <textarea
                        id="refund_reason"
                        rows={2}
                        maxLength={255}
                        value={reason}
                        aria-invalid={Boolean(errors.reason)}
                        onChange={(event) => {
                            touch();
                            setReason(event.target.value);
                            setErrors({});
                        }}
                        className={`w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:outline-none focus:ring-1 ${
                            errors.reason ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                        }`}
                    />
                    {errors.reason && (
                        <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                            &#9888; {errors.reason}
                        </p>
                    )}
                </div>
            </form>
        </SlideOver>
    );
}

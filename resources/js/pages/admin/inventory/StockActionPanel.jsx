import { useEffect, useRef, useState } from 'react';
import SlideOver from '../SlideOver';
import { failureMessage, fieldErrors, normalizeMoney, request } from '../catalog/catalogApi';
import { ProductPicker, QUANTITY_PATTERN, fromThousandths, quantityText, toThousandths } from './inventoryParts';
import { packCostDividesExactly, packUnitCost, packUnits } from './packMath';
import { plainQuantity } from './stockDocsParts';

const RECEIPT_TYPES = [
    { id: 'PURCHASE_RECEIPT', label: 'Purchase receipt', hint: 'Stock delivered by a supplier.' },
    { id: 'OPENING_STOCK', label: 'Opening stock', hint: 'What is already on the shelf when you start tracking it.' },
];

const ADJUSTMENT_TYPES = [
    { id: 'STOCK_ADJUSTMENT_IN', label: 'Add stock', hint: 'A count found more than the system shows.', direction: 1 },
    { id: 'STOCK_ADJUSTMENT_OUT', label: 'Remove stock', hint: 'A count found less than the system shows.', direction: -1 },
    { id: 'DAMAGE', label: 'Damaged', hint: 'Broken or spoiled and no longer sellable.', direction: -1 },
    { id: 'EXPIRED', label: 'Expired', hint: 'Past its date and taken off sale.', direction: -1 },
];

const inputClass = (error, mono = false) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${mono ? 'font-mono' : ''} ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

function Field({ id, label, required, hint, error, children }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs text-slate-400">
                {label} {required && <span className="text-emerald-400">*</span>}
            </label>
            {children}
            {error ? (
                <p id={`${id}_error`} role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            ) : (
                hint && <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
            )}
        </div>
    );
}

function validate(mode, values, packaging) {
    const errors = {};
    if (values.product_id === '') {
        errors.product_id = 'Choose a product.';
    }
    if (packaging) {
        if (!QUANTITY_PATTERN.test(values.quantity.trim()) || toThousandths(values.quantity.trim()) <= 0) {
            errors.quantity = 'Enter how many packs arrived, above zero, with up to 3 decimals.';
        } else if (packUnits(values.quantity.trim(), packaging.units_per_base) === null) {
            errors.quantity = 'That many packs does not come to a whole number of thousandths of a unit. Check the number of packs.';
        }
    } else if (!QUANTITY_PATTERN.test(values.quantity.trim()) || toThousandths(values.quantity.trim()) <= 0) {
        errors.quantity = 'Enter a quantity above zero, with up to 3 decimals.';
    }
    if (mode === 'receipt' && values.unit_cost.trim() !== '' && normalizeMoney(values.unit_cost) === null) {
        errors.unit_cost = 'Enter an amount such as 42 or 42.50.';
    }
    if (mode === 'adjustment' && values.reason.trim() === '') {
        errors.reason = 'Say why the stock is changing.';
    }
    return errors;
}

/**
 * Slide-over for inventoryReceiptCreate (mode="receipt") and inventoryAdjustmentCreate
 * (mode="adjustment"). One Idempotency-Key per intent: it is reused when the same request is retried
 * after a dropped connection (so the stock is never counted twice) and replaced as soon as any field
 * changes (a different request must not reuse a key). Removing more than is on hand is allowed -- the
 * API records it and the balance goes negative -- so the panel warns instead of blocking.
 */
export default function StockActionPanel({ mode, products, initialProductId = '', defaultLocation, onDone, onClose, onUnauthorized }) {
    const isReceipt = mode === 'receipt';
    const [values, setValues] = useState({
        product_id: initialProductId,
        packaging_id: '',
        movement_type: isReceipt ? 'PURCHASE_RECEIPT' : 'STOCK_ADJUSTMENT_IN',
        quantity: '',
        unit_cost: '',
        note: '',
        reason: '',
    });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [onHand, setOnHand] = useState(null); // null = unknown, otherwise a decimal string
    const [packagings, setPackagings] = useState([]); // the product's packs that may be received in (receipts only)
    const keyRef = useRef(crypto.randomUUID());

    useEffect(() => {
        setPackagings([]);
        setValues((prior) => (prior.packaging_id === '' ? prior : { ...prior, packaging_id: '' }));
        if (!isReceipt || values.product_id === '') {
            return undefined;
        }
        let cancelled = false;
        request(`/api/v1/products/${values.product_id}/barcodes?per_page=100`).then((response) => {
            if (!cancelled && response.ok) {
                setPackagings(response.body.data.filter((row) => row.can_receive));
            }
        });
        return () => {
            cancelled = true;
        };
    }, [isReceipt, values.product_id]);

    useEffect(() => {
        setOnHand(null);
        if (values.product_id === '') {
            return undefined;
        }
        let cancelled = false;
        request(`/api/v1/inventory/stock?product_id=${values.product_id}&per_page=100`).then((response) => {
            if (cancelled || !response.ok) {
                return;
            }
            const balance = response.body.data.find((row) => row.location_id === defaultLocation?.id);
            setOnHand(balance ? balance.quantity_on_hand : '0.000');
        });
        return () => {
            cancelled = true;
        };
    }, [values.product_id, defaultLocation?.id]);

    function set(field, value) {
        keyRef.current = crypto.randomUUID();
        setValues((prior) => ({ ...prior, [field]: value }));
        setErrors((prior) => ({ ...prior, [field]: undefined }));
        setFormError(null);
    }

    const direction = isReceipt ? 1 : (ADJUSTMENT_TYPES.find((type) => type.id === values.movement_type)?.direction ?? 1);
    // Received in a pack: the server turns packs into single units and derives the unit cost; this only previews it.
    const packaging = isReceipt ? packagings.find((candidate) => candidate.id === values.packaging_id) : undefined;
    const packName = packaging ? (packaging.name ?? 'pack') : null;
    const unitsText = packaging && QUANTITY_PATTERN.test(values.quantity.trim()) && toThousandths(values.quantity.trim()) > 0 ? packUnits(values.quantity.trim(), packaging.units_per_base) : null;
    const packCostText = packaging && values.unit_cost.trim() !== '' ? normalizeMoney(values.unit_cost) : null;
    const derivedUnitCost = unitsText && packCostText ? packUnitCost(packCostText, packaging.units_per_base) : null;
    const effectiveUnits = packaging ? unitsText : values.quantity.trim();
    const quantityValid = effectiveUnits !== null && QUANTITY_PATTERN.test(effectiveUnits) && toThousandths(effectiveUnits) > 0;
    const after = onHand !== null && quantityValid ? fromThousandths(toThousandths(onHand) + direction * toThousandths(effectiveUnits)) : null;
    const product = products.find((candidate) => candidate.id === values.product_id);

    async function submit(event) {
        event.preventDefault();
        const clientErrors = validate(mode, values, packaging);
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setSaving(true);
        setFormError(null);
        const body = packaging
            ? { product_id: values.product_id, packaging_id: packaging.id, packs: values.quantity.trim(), movement_type: values.movement_type }
            : { product_id: values.product_id, quantity: values.quantity.trim(), movement_type: values.movement_type };
        if (isReceipt) {
            if (values.unit_cost.trim() !== '') {
                body[packaging ? 'pack_cost' : 'unit_cost'] = normalizeMoney(values.unit_cost);
            }
            if (values.note.trim() !== '') {
                body.note = values.note.trim();
            }
        } else {
            body.reason = values.reason.trim();
        }
        const result = await request(isReceipt ? '/api/v1/inventory/receipts' : '/api/v1/inventory/adjustments', {
            method: 'POST',
            body,
            headers: { 'Idempotency-Key': keyRef.current },
        });
        setSaving(false);
        if (result.ok) {
            onDone(result.body, product);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        const code = result.body?.error?.code;
        if (code === 'STOCK_ADJUSTMENT_REASON_REQUIRED') {
            setErrors({ reason: 'Say why the stock is changing.' });
            return;
        }
        const serverErrors = fieldErrors(result);
        if (serverErrors.packaging_id) {
            setFormError(serverErrors.packaging_id);
            return;
        }
        if (Object.keys(serverErrors).length > 0) {
            // the panel's one quantity and cost boxes stand for packs and the pack cost when a pack is chosen
            setErrors({ ...serverErrors, quantity: serverErrors.quantity ?? serverErrors.packs, unit_cost: serverErrors.unit_cost ?? serverErrors.pack_cost });
            return;
        }
        if (result.status === 0) {
            setFormError('The connection dropped, so this may not have been recorded. Press the button again — it will not be counted twice.');
        } else if (code === 'TERMINAL_NOT_ENROLLED') {
            setFormError('This browser is not enrolled as a terminal, so it cannot record stock changes. An administrator can enroll it under Terminals.');
        } else if (code === 'IDEMPOTENCY_KEY_REUSED') {
            keyRef.current = crypto.randomUUID();
            setFormError('That request was already used for something different. Review the form and record it again.');
        } else {
            setFormError(failureMessage(result, isReceipt ? 'The receipt could not be recorded.' : 'The adjustment could not be recorded.'));
        }
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="stock_action_form"
                disabled={saving}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Recording…' : isReceipt ? 'Record receipt' : 'Record adjustment'}
            </button>
        </div>
    );

    const types = isReceipt ? RECEIPT_TYPES : ADJUSTMENT_TYPES;

    return (
        <SlideOver
            titleId="stock_panel_title"
            title={isReceipt ? 'Receive stock' : 'Adjust stock'}
            badge={defaultLocation ? defaultLocation.name : null}
            onClose={onClose}
            footer={footer}
        >
            <form id="stock_action_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}

                <Field id="stock_product" label="Product" required error={errors.product_id}>
                    <ProductPicker
                        id="stock_product"
                        products={products}
                        value={values.product_id}
                        error={errors.product_id}
                        disabled={initialProductId !== ''}
                        onChange={(id) => set('product_id', id)}
                    />
                </Field>

                {isReceipt && packagings.length > 0 && (
                    <fieldset>
                        <legend className="mb-1 text-xs text-slate-400">Received as</legend>
                        <div className="grid grid-cols-1 gap-2">
                            {[{ id: '', label: `Single ${product?.unit_of_measure ?? 'units'}`, hint: 'Count each piece.' }, ...packagings.map((row) => ({
                                id: row.id,
                                label: row.name ?? `Barcode ${row.barcode}`,
                                hint: `${plainQuantity(row.units_per_base)} ${product?.unit_of_measure ?? 'units'} in each.`,
                            }))].map((option) => (
                                <label
                                    key={option.id || 'units'}
                                    className={`flex cursor-pointer items-start gap-2 rounded-md border p-2.5 ${
                                        values.packaging_id === option.id ? 'border-emerald-500 bg-emerald-950/20' : 'border-slate-700 bg-slate-950/40 hover:bg-slate-800/60'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="received_as"
                                        value={option.id}
                                        checked={values.packaging_id === option.id}
                                        onChange={() => set('packaging_id', option.id)}
                                        className="mt-0.5 accent-emerald-500"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-100">{option.label}</span>
                                        <span className="block text-[11px] text-slate-400">{option.hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}

                <fieldset>
                    <legend className="mb-1 text-xs text-slate-400">
                        {isReceipt ? 'Type' : 'What happened'} <span className="text-emerald-400">*</span>
                    </legend>
                    <div className="grid grid-cols-1 gap-2">
                        {types.map((type) => (
                            <label
                                key={type.id}
                                className={`flex cursor-pointer items-start gap-2 rounded-md border p-2.5 ${
                                    values.movement_type === type.id ? 'border-emerald-500 bg-emerald-950/20' : 'border-slate-700 bg-slate-950/40 hover:bg-slate-800/60'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="movement_type"
                                    value={type.id}
                                    checked={values.movement_type === type.id}
                                    onChange={() => set('movement_type', type.id)}
                                    className="mt-0.5 accent-emerald-500"
                                />
                                <span>
                                    <span className="block text-sm font-medium text-slate-100">{type.label}</span>
                                    <span className="block text-[11px] text-slate-400">{type.hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                <Field
                    id="stock_quantity"
                    label={packaging ? `Number of ${packName}` : `Quantity${product ? ` (${product.unit_of_measure})` : ''}`}
                    required
                    hint={packaging ? 'How many arrived. Up to 3 decimals, for a part of a pack.' : 'Up to 3 decimals, for items sold by weight or volume.'}
                    error={errors.quantity}
                >
                    <input
                        id="stock_quantity"
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        value={values.quantity}
                        aria-invalid={Boolean(errors.quantity)}
                        onChange={(event) => set('quantity', event.target.value)}
                        className={inputClass(errors.quantity, true)}
                    />
                </Field>

                {packaging && unitsText && (
                    <div className="rounded-md border border-emerald-900/60 bg-emerald-950/20 px-3 py-2 text-xs text-slate-200" aria-live="polite">
                        <p className="font-mono">
                            = {quantityText(unitsText)} {product?.unit_of_measure ?? 'units'}
                            {derivedUnitCost && <> at ₱{derivedUnitCost} each</>}
                        </p>
                        {derivedUnitCost && !packCostDividesExactly(packCostText, packaging.units_per_base) && (
                            <p className="mt-1 text-[11px] text-slate-400">
                                The cost per {product?.unit_of_measure ?? 'unit'} is rounded to the centavo; the cost of one {packName} (₱{packCostText}) is kept on the record.
                            </p>
                        )}
                    </div>
                )}

                {values.product_id !== '' && (
                    <div className="rounded-md border border-slate-800 bg-slate-950/60 px-3 py-2 font-mono text-xs" aria-live="polite">
                        <div className="flex justify-between text-slate-400">
                            <span>On hand{defaultLocation ? ` at ${defaultLocation.name}` : ''}</span>
                            <span className="text-slate-200">{onHand === null ? '…' : quantityText(onHand)}</span>
                        </div>
                        <div className="mt-1 flex justify-between text-slate-400">
                            <span>After this</span>
                            <span className={after !== null && toThousandths(after) < 0 ? 'text-amber-400' : 'text-emerald-400'}>{after === null ? '—' : quantityText(after)}</span>
                        </div>
                        {after !== null && toThousandths(after) < 0 && (
                            <p className="mt-2 font-sans text-[11px] text-amber-400">
                                &#9888; This removes more than the system shows on hand, so the balance will go below zero. It is still recorded; check the count if that is not expected.
                            </p>
                        )}
                    </div>
                )}

                {isReceipt ? (
                    <>
                        <Field
                            id="stock_unit_cost"
                            label={packaging ? `Cost of one ${packName} (₱)` : 'Unit cost (₱)'}
                            hint={packaging ? `Optional. What you paid for one ${packName}; the cost of each ${product?.unit_of_measure ?? 'unit'} is worked out from it.` : 'Optional. What you paid for one unit.'}
                            error={errors.unit_cost}
                        >
                            <input
                                id="stock_unit_cost"
                                type="text"
                                inputMode="decimal"
                                autoComplete="off"
                                value={values.unit_cost}
                                aria-invalid={Boolean(errors.unit_cost)}
                                onChange={(event) => set('unit_cost', event.target.value)}
                                className={inputClass(errors.unit_cost, true)}
                            />
                        </Field>
                        <Field id="stock_note" label="Note" hint="Optional. For example a supplier or delivery receipt number." error={errors.note}>
                            <input
                                id="stock_note"
                                type="text"
                                autoComplete="off"
                                maxLength={255}
                                value={values.note}
                                onChange={(event) => set('note', event.target.value)}
                                className={inputClass(errors.note)}
                            />
                        </Field>
                    </>
                ) : (
                    <Field id="stock_reason" label="Reason" required hint="Kept with the record so the change can be explained later." error={errors.reason}>
                        <textarea
                            id="stock_reason"
                            rows={3}
                            maxLength={255}
                            value={values.reason}
                            aria-invalid={Boolean(errors.reason)}
                            onChange={(event) => set('reason', event.target.value)}
                            className={inputClass(errors.reason)}
                        />
                    </Field>
                )}
            </form>
        </SlideOver>
    );
}

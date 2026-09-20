import { useEffect, useRef, useState } from 'react';
import SlideOver from '../SlideOver';
import { failureMessage, request } from '../catalog/catalogApi';
import { ProductPicker, QUANTITY_PATTERN, quantityText, toThousandths } from './inventoryParts';

const inputClass = (error, mono = false) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${mono ? 'font-mono' : ''} ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

const validQuantity = (text) => QUANTITY_PATTERN.test(text.trim()) && toThousandths(text.trim()) > 0;

/**
 * Slide-over for stockTransferCreate: move stock between two locations of this store. The move is immediate and
 * final (correct a wrong one by moving it back). One Idempotency-Key per intent: it is reused when the same
 * request is retried after a dropped connection (so the stock is never moved twice) and replaced as soon as any
 * field changes. Moving more than the source shows on hand is allowed -- the API records it and the balance goes
 * below zero -- so the panel warns instead of blocking, as the Stock page does for an adjustment.
 */
export default function TransferPanel({ locations, products, onDone, onClose, onUnauthorized }) {
    const defaultLocation = locations.find((location) => location.is_default) ?? locations[0];
    const [fromId, setFromId] = useState(defaultLocation?.id ?? '');
    const [toId, setToId] = useState(locations.find((location) => location.id !== defaultLocation?.id)?.id ?? '');
    const [items, setItems] = useState([]); // [{ product_id, quantity }]
    const [note, setNote] = useState('');
    const [productId, setProductId] = useState('');
    const [quantity, setQuantity] = useState('');
    const [lineError, setLineError] = useState(null);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [balances, setBalances] = useState({}); // product_id -> [{ location_id, quantity_on_hand }]
    const keyRef = useRef(crypto.randomUUID());

    const productsById = Object.fromEntries(products.map((product) => [product.id, product]));
    const onHandAtSource = (id) => {
        const rows = balances[id];
        return rows === undefined ? null : (rows.find((row) => row.location_id === fromId)?.quantity_on_hand ?? '0.000');
    };

    useEffect(() => {
        if (productId === '' || balances[productId] !== undefined) {
            return undefined;
        }
        let cancelled = false;
        request(`/api/v1/inventory/stock?product_id=${productId}&per_page=100`).then((response) => {
            if (!cancelled && response.ok) {
                setBalances((prior) => ({ ...prior, [productId]: response.body.data }));
            }
        });
        return () => {
            cancelled = true;
        };
    }, [productId, balances]);

    function changed() {
        keyRef.current = crypto.randomUUID();
        setErrors({});
        setFormError(null);
    }

    function addItem(event) {
        event.preventDefault();
        if (productId === '') {
            setLineError('Choose a product.');
            return;
        }
        if (!validQuantity(quantity)) {
            setLineError('Enter a quantity above zero, with up to 3 decimals.');
            return;
        }
        changed();
        setItems((prior) => [...prior.filter((item) => item.product_id !== productId), { product_id: productId, quantity: quantity.trim() }]);
        setProductId('');
        setQuantity('');
        setLineError(null);
        setTimeout(() => document.getElementById('transfer_product')?.focus(), 0);
    }

    function removeItem(id) {
        changed();
        setItems((prior) => prior.filter((item) => item.product_id !== id));
    }

    async function submit(event) {
        event.preventDefault();
        const clientErrors = {};
        if (fromId === '') {
            clientErrors.from = 'Choose where the stock is now.';
        }
        if (toId === '') {
            clientErrors.to = 'Choose where it is going.';
        } else if (toId === fromId) {
            clientErrors.to = 'Choose a different location to move the stock to.';
        }
        if (items.length === 0) {
            clientErrors.items = 'Add at least one product to move.';
        }
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setSaving(true);
        setFormError(null);
        const body = { from_location_id: fromId, to_location_id: toId, items };
        if (note.trim() !== '') {
            body.note = note.trim();
        }
        const result = await request('/api/v1/inventory/transfers', { method: 'POST', body, headers: { 'Idempotency-Key': keyRef.current } });
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
        if (result.status === 0) {
            setFormError('The connection dropped, so this may not have been recorded. Press the button again — the stock will not be moved twice.');
        } else if (code === 'TERMINAL_NOT_ENROLLED') {
            setFormError('This browser is not enrolled as a terminal, so it cannot move stock. An administrator can enroll it under Terminals.');
        } else if (code === 'IDEMPOTENCY_KEY_REUSED') {
            keyRef.current = crypto.randomUUID();
            setFormError('That request was already used for something different. Review the transfer and record it again.');
        } else if (result.status === 422 && result.body?.error?.details) {
            const first = Object.values(result.body.error.details)[0];
            setFormError(Array.isArray(first) ? first[0] : failureMessage(result, 'The transfer could not be recorded.'));
        } else {
            setFormError(failureMessage(result, 'The transfer could not be recorded.'));
        }
    }

    const nameOfLocation = (id) => locations.find((location) => location.id === id)?.name ?? '—';
    const picked = productsById[productId];
    const pickedOnHand = productId !== '' ? onHandAtSource(productId) : null;

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="transfer_form"
                disabled={saving}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Moving…' : 'Move stock'}
            </button>
        </div>
    );

    return (
        <SlideOver titleId="transfer_panel_title" title="Move stock" onClose={onClose} footer={footer}>
            <form id="transfer_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}
                <p className="text-sm text-slate-400">
                    Move stock from one location of this store to another. It takes effect at once and cannot be edited; if it was a mistake, move it back.
                </p>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="transfer_from" className="mb-1 block text-xs text-slate-400">
                            From <span className="text-emerald-400">*</span>
                        </label>
                        <select
                            id="transfer_from"
                            value={fromId}
                            aria-invalid={Boolean(errors.from)}
                            onChange={(event) => {
                                changed();
                                setFromId(event.target.value);
                            }}
                            className={inputClass(errors.from)}
                        >
                            {locations.map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </select>
                        {errors.from && <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">&#9888; {errors.from}</p>}
                    </div>
                    <div>
                        <label htmlFor="transfer_to" className="mb-1 block text-xs text-slate-400">
                            To <span className="text-emerald-400">*</span>
                        </label>
                        <select
                            id="transfer_to"
                            value={toId}
                            aria-invalid={Boolean(errors.to)}
                            onChange={(event) => {
                                changed();
                                setToId(event.target.value);
                            }}
                            className={inputClass(errors.to)}
                        >
                            {locations.map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </select>
                        {errors.to && <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">&#9888; {errors.to}</p>}
                    </div>
                </div>

                <div className="rounded-md border border-slate-800 bg-slate-950/40 p-3">
                    <label htmlFor="transfer_product" className="mb-1 block text-xs text-slate-400">
                        Add a product to move
                    </label>
                    <ProductPicker id="transfer_product" products={products} value={productId} error={lineError && productId === '' ? lineError : null} onChange={(value) => { setProductId(value); setLineError(null); }} />
                    {productId !== '' && (
                        <div className="mt-3">
                            <label htmlFor="transfer_quantity" className="mb-1 block text-xs text-slate-400">
                                Quantity{picked ? ` (${picked.unit_of_measure})` : ''}
                            </label>
                            <div className="flex gap-2">
                                <input
                                    id="transfer_quantity"
                                    type="text"
                                    inputMode="decimal"
                                    autoComplete="off"
                                    autoFocus
                                    value={quantity}
                                    aria-invalid={Boolean(lineError)}
                                    onChange={(event) => {
                                        setQuantity(event.target.value);
                                        setLineError(null);
                                    }}
                                    onKeyDown={(event) => event.key === 'Enter' && addItem(event)}
                                    className={inputClass(lineError, true)}
                                />
                                <button
                                    type="button"
                                    onClick={addItem}
                                    className="min-h-11 shrink-0 rounded-md border border-emerald-600 px-3 py-2 text-sm font-medium text-emerald-300 hover:bg-emerald-950 lg:min-h-0"
                                >
                                    Add
                                </button>
                            </div>
                            {lineError && <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">&#9888; {lineError}</p>}
                            <p className="mt-1 text-[11px] text-slate-500">
                                On hand at {nameOfLocation(fromId)}: <span className="font-mono text-slate-300">{pickedOnHand === null ? '…' : quantityText(pickedOnHand)}</span>
                            </p>
                        </div>
                    )}
                </div>

                {errors.items && <p role="alert" className="font-mono text-[11px] text-rose-400">&#9888; {errors.items}</p>}

                {items.length > 0 && (
                    <ul aria-label="Products to move" className="divide-y divide-slate-800 rounded-md border border-slate-800">
                        {items.map((item) => {
                            const product = productsById[item.product_id];
                            const onHand = onHandAtSource(item.product_id);
                            const short = onHand !== null && toThousandths(item.quantity) > toThousandths(onHand);
                            return (
                                <li key={item.product_id} className="flex items-start justify-between gap-3 px-3 py-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm text-slate-100">{product?.name ?? '(unknown product)'}</p>
                                        <p className="font-mono text-[11px] text-slate-500">{product?.sku}</p>
                                        {short && (
                                            <p className="mt-1 text-[11px] text-amber-400">
                                                &#9888; {nameOfLocation(fromId)} shows only {quantityText(onHand)}, so it will go below zero. It is still recorded; check the count if that is not expected.
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        <span className="font-mono text-sm tabular-nums text-slate-100">{quantityText(item.quantity)}</span>
                                        <button
                                            type="button"
                                            onClick={() => removeItem(item.product_id)}
                                            aria-label={`Remove ${product?.name ?? 'product'}`}
                                            className="min-h-11 px-1 text-xs text-slate-500 hover:text-rose-400 lg:min-h-0"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <div>
                    <label htmlFor="transfer_note" className="mb-1 block text-xs text-slate-400">
                        Note
                    </label>
                    <input
                        id="transfer_note"
                        type="text"
                        autoComplete="off"
                        maxLength={255}
                        value={note}
                        onChange={(event) => {
                            changed();
                            setNote(event.target.value);
                        }}
                        className={inputClass(false)}
                    />
                    <p className="mt-1 text-[11px] text-slate-500">Optional. For example “Restock the counter”.</p>
                </div>
            </form>
        </SlideOver>
    );
}

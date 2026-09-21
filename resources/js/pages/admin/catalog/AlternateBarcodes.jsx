import { useEffect, useRef, useState } from 'react';
import { failureMessage, fetchAll, fieldErrors, request } from './catalogApi';

const UNITS_PATTERN = /^\d{1,7}(\.\d{1,3})?$/;

const fieldClass = (error) =>
    `min-h-11 min-w-0 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

/** "24" for 24.000, "0.5" for 0.500. */
function plain(units) {
    return String(units).replace(/\.?0+$/, '') || '0';
}

/**
 * The packagings of a product: extra barcodes it can be scanned by and named packs such as "Case (24 units)"
 * (productBarcodeList / Create / Delete). Stock is always kept in the product's own unit; a pack only says how many of
 * those units it holds, which is what receiving uses to turn "5 cases" into 120 units. A pack needs no barcode. Each
 * change is saved at once, separately from the product form's own Save button, because the server is the only judge of
 * whether a code or name is free. A barcode scanner types the code and presses Enter, which adds it (Enter is stopped
 * here so it does not submit the surrounding product form).
 */
export default function AlternateBarcodes({ productId, unitOfMeasure, onUnauthorized }) {
    const [rows, setRows] = useState(null); // null = loading
    const [loadFailed, setLoadFailed] = useState(false);
    const [name, setName] = useState('');
    const [units, setUnits] = useState('1');
    const [code, setCode] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const codeRef = useRef(null);
    const unauthorized = useRef(onUnauthorized); // latest callback without re-running the load when the parent re-renders
    unauthorized.current = onUnauthorized;

    useEffect(() => {
        let cancelled = false;
        setRows(null);
        setLoadFailed(false);
        fetchAll(`/api/v1/products/${productId}/barcodes`).then((result) => {
            if (cancelled) {
                return;
            }
            if (result.ok) {
                setRows(result.rows);
            } else if (result.status === 401) {
                unauthorized.current();
            } else {
                setLoadFailed(true);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [productId]);

    function clearError(field) {
        setErrors((prior) => ({ ...prior, [field]: undefined, form: undefined }));
    }

    async function add() {
        if (busy) {
            return;
        }
        const barcode = code.trim();
        const label = name.trim();
        const clientErrors = {};
        if (barcode === '' && label === '') {
            clientErrors.form = 'Enter or scan a barcode, or give the pack a name.';
        }
        if (!UNITS_PATTERN.test(units.trim()) || Number(units) <= 0) {
            clientErrors.units = 'Units per pack must be above zero, with up to 3 decimals.';
        }
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setBusy(true);
        setErrors({});
        const body = { units_per_base: units.trim() };
        if (barcode !== '') {
            body.barcode = barcode;
        }
        if (label !== '') {
            body.name = label;
        }
        const result = await request(`/api/v1/products/${productId}/barcodes`, { method: 'POST', body });
        setBusy(false);
        if (result.ok) {
            setRows((prior) => [...(prior ?? []), result.body]);
            setName('');
            setUnits('1');
            setCode('');
            codeRef.current?.focus();
            return;
        }
        if (result.status === 401) {
            unauthorized.current();
            return;
        }
        const server = fieldErrors(result);
        setErrors(Object.keys(server).length > 0 ? server : { form: failureMessage(result, 'This could not be added.') });
    }

    async function remove(row) {
        setBusy(true);
        setErrors({});
        const result = await request(`/api/v1/products/${productId}/barcodes/${row.id}`, { method: 'DELETE' });
        setBusy(false);
        if (result.ok) {
            setRows((prior) => prior.filter((candidate) => candidate.id !== row.id));
            return;
        }
        if (result.status === 401) {
            unauthorized.current();
            return;
        }
        setErrors({ form: failureMessage(result, 'This could not be removed.') });
    }

    const onEnter = (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            add();
        }
    };
    const message = errors.form ?? errors.barcode ?? errors.name ?? errors.units_per_base ?? errors.units;
    const unit = unitOfMeasure ? ` ${unitOfMeasure}` : ' units';

    return (
        <div>
            <label htmlFor="product_alt_barcode" className="mb-1 block text-xs text-slate-400">
                Packs and other barcodes
            </label>

            {rows === null && !loadFailed && <div className="h-9 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading packs" />}
            {loadFailed && (
                <p role="alert" className="mb-2 text-xs text-rose-400">
                    The packs and barcodes could not be loaded. Close and reopen this product to try again.
                </p>
            )}

            {rows !== null && rows.length > 0 && (
                <ul aria-label="Packs and other barcodes" className="mb-2 divide-y divide-slate-800 rounded-md border border-slate-800">
                    {rows.map((row) => {
                        const isPack = row.name !== null || Number(row.units_per_base) !== 1;
                        return (
                            <li key={row.id} className="flex items-center justify-between gap-3 px-3 py-1.5">
                                <span className="min-w-0">
                                    {isPack && (
                                        <span className="block text-sm text-slate-100">
                                            {row.name ?? 'Pack'} <span className="font-mono text-[12px] text-slate-400">&times; {plain(row.units_per_base)}{unit}</span>
                                        </span>
                                    )}
                                    <span className={`block break-all font-mono ${isPack ? 'text-[11px] text-slate-500' : 'text-sm text-slate-100'}`}>
                                        {row.barcode ?? 'no barcode'}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => remove(row)}
                                    aria-label={`Remove ${row.name ?? row.barcode}`}
                                    className="min-h-11 shrink-0 px-1 text-xs text-slate-500 hover:text-rose-400 disabled:opacity-40 lg:min-h-0"
                                >
                                    Remove
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {rows !== null && rows.length === 0 && <p className="mb-2 text-[11px] text-slate-500">No packs or other barcodes yet.</p>}

            {rows !== null && (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-[minmax(0,1fr)_6rem_minmax(0,1.4fr)_auto]">
                    <input
                        id="product_alt_name"
                        type="text"
                        autoComplete="off"
                        maxLength={60}
                        value={name}
                        placeholder="Name, e.g. Case"
                        aria-label="Pack name"
                        aria-invalid={Boolean(errors.name)}
                        onChange={(event) => {
                            setName(event.target.value);
                            clearError('name');
                        }}
                        onKeyDown={onEnter}
                        className={fieldClass(errors.name)}
                    />
                    <input
                        id="product_alt_units"
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        value={units}
                        placeholder="Units"
                        aria-label="Units per pack"
                        aria-invalid={Boolean(errors.units || errors.units_per_base)}
                        onChange={(event) => {
                            setUnits(event.target.value);
                            clearError('units');
                            clearError('units_per_base');
                        }}
                        onKeyDown={onEnter}
                        className={`${fieldClass(errors.units || errors.units_per_base)} text-right font-mono`}
                    />
                    <input
                        id="product_alt_barcode"
                        ref={codeRef}
                        type="text"
                        autoComplete="off"
                        maxLength={255}
                        value={code}
                        placeholder="Scan or type a barcode"
                        aria-label="Barcode"
                        aria-invalid={Boolean(errors.barcode)}
                        aria-describedby={message ? 'product_alt_barcode_error' : undefined}
                        onChange={(event) => {
                            setCode(event.target.value);
                            clearError('barcode');
                        }}
                        onKeyDown={onEnter}
                        className={`${fieldClass(errors.barcode)} col-span-2 font-mono placeholder:font-sans sm:col-span-1`}
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={add}
                        className="col-span-2 min-h-11 shrink-0 rounded-md border border-emerald-600 px-3 py-2 text-sm font-medium text-emerald-300 hover:bg-emerald-950 disabled:opacity-50 sm:col-span-1 lg:min-h-0"
                    >
                        {busy ? 'Saving…' : 'Add'}
                    </button>
                </div>
            )}
            {message ? (
                <p id="product_alt_barcode_error" role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {message}
                </p>
            ) : (
                <p className="mt-1 text-[11px] text-slate-500">
                    Give a pack a name and how many{unit} it holds (a case of 24 = 24) to receive stock by the case; a barcode is optional. Stock is always counted in
                    {unit}. Scanning a barcode here finds this product. Added and removed at once, without Save changes.
                </p>
            )}
        </div>
    );
}

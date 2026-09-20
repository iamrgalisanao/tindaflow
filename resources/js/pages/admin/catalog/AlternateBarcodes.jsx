import { useEffect, useRef, useState } from 'react';
import { failureMessage, fetchAll, fieldErrors, request } from './catalogApi';

/**
 * The extra barcodes a product can be scanned by (productBarcodeList / Create / Delete). Each change is saved at once,
 * separately from the product form's own Save button, because the server is the only judge of whether a code is free.
 * A barcode scanner types the code and presses Enter, which adds it (Enter is stopped here so it does not submit the
 * surrounding product form).
 */
export default function AlternateBarcodes({ productId, onUnauthorized }) {
    const [rows, setRows] = useState(null); // null = loading
    const [loadFailed, setLoadFailed] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const inputRef = useRef(null);
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

    async function add() {
        const barcode = code.trim();
        if (barcode === '') {
            setError('Enter or scan a barcode.');
            return;
        }
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        const result = await request(`/api/v1/products/${productId}/barcodes`, { method: 'POST', body: { barcode } });
        setBusy(false);
        if (result.ok) {
            setRows((prior) => [...(prior ?? []), result.body]);
            setCode('');
            inputRef.current?.focus();
            return;
        }
        if (result.status === 401) {
            unauthorized.current();
            return;
        }
        setError(fieldErrors(result).barcode ?? failureMessage(result, 'The barcode could not be added.'));
    }

    async function remove(row) {
        setBusy(true);
        setError(null);
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
        setError(failureMessage(result, 'The barcode could not be removed.'));
    }

    return (
        <div>
            <label htmlFor="product_alt_barcode" className="mb-1 block text-xs text-slate-400">
                Other barcodes
            </label>

            {rows === null && !loadFailed && <div className="h-9 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading barcodes" />}
            {loadFailed && (
                <p role="alert" className="mb-2 text-xs text-rose-400">
                    The other barcodes could not be loaded. Close and reopen this product to try again.
                </p>
            )}

            {rows !== null && rows.length > 0 && (
                <ul aria-label="Other barcodes" className="mb-2 divide-y divide-slate-800 rounded-md border border-slate-800">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center justify-between gap-3 px-3 py-1.5">
                            <span className="min-w-0 break-all font-mono text-sm text-slate-100">{row.barcode}</span>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => remove(row)}
                                aria-label={`Remove barcode ${row.barcode}`}
                                className="min-h-11 shrink-0 px-1 text-xs text-slate-500 hover:text-rose-400 disabled:opacity-40 lg:min-h-0"
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {rows !== null && rows.length === 0 && <p className="mb-2 text-[11px] text-slate-500">No other barcodes yet.</p>}

            {rows !== null && (
                <div className="flex gap-2">
                    <input
                        id="product_alt_barcode"
                        ref={inputRef}
                        type="text"
                        autoComplete="off"
                        maxLength={255}
                        value={code}
                        placeholder="Scan or type a barcode"
                        aria-invalid={Boolean(error)}
                        aria-describedby={error ? 'product_alt_barcode_error' : undefined}
                        onChange={(event) => {
                            setCode(event.target.value);
                            setError(null);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                add();
                            }
                        }}
                        className={`min-h-11 min-w-0 flex-1 rounded-md border bg-slate-950 px-3 py-1.5 font-mono text-sm text-slate-100 placeholder:font-sans placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
                            error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                        }`}
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={add}
                        className="min-h-11 shrink-0 rounded-md border border-emerald-600 px-3 py-2 text-sm font-medium text-emerald-300 hover:bg-emerald-950 disabled:opacity-50 lg:min-h-0"
                    >
                        {busy ? 'Saving…' : 'Add'}
                    </button>
                </div>
            )}
            {error ? (
                <p id="product_alt_barcode_error" role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            ) : (
                <p className="mt-1 text-[11px] text-slate-500">
                    Scanning any of these finds this product, for example another supplier&rsquo;s packaging. Added and removed at once, without Save changes.
                </p>
            )}
        </div>
    );
}

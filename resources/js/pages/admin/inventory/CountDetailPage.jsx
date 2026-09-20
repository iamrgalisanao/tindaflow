import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ConfirmDialog, ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime } from '../reports/formatters';
import { ProductPicker, QUANTITY_PATTERN, quantityText, useInventoryLookups } from './inventoryParts';
import { CountStatusBadge, TerminalNotice, Variance, plainQuantity, useTerminalState } from './stockDocsParts';

const inputClass = (error) =>
    `min-h-11 rounded-md border bg-slate-950 px-3 py-1.5 text-right font-mono text-sm tabular-nums text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

const validQuantity = (text) => QUANTITY_PATTERN.test(text.trim());

/**
 * /admin/inventory/counts/:id -- the count worksheet. While it is in progress you add each product with what
 * you found (a product counted again replaces its line); the system shows what it expected beside it and the
 * difference. Nothing changes stock until the count is posted, and products you do not add are left alone.
 * Drafting needs only STOCK_ADJUST; posting writes the stock ledger, so it also needs an enrolled terminal.
 */
export default function CountDetailPage() {
    const { id } = useParams();
    const { user, setUser } = useAuth();
    const lookups = useInventoryLookups();
    const terminal = useTerminalState();
    const [count, setCount] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [reloadKey, setReloadKey] = useState(0);
    const [productId, setProductId] = useState('');
    const [quantity, setQuantity] = useState('');
    const [quantityError, setQuantityError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState(null); // an error from the last change, shown above the worksheet
    const [confirm, setConfirm] = useState(null); // null | 'post' | 'cancel'
    const [toast, setToast] = useState(null);
    const postKey = useRef(crypto.randomUUID());
    const dismissToast = useCallback(() => setToast(null), []);

    const open = count?.status === 'OPEN';
    const canManageTerminals = user.capabilities.includes('TERMINAL_MANAGE');

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setFailure(null);
        request(`/api/v1/inventory/counts/${id}`).then((response) => {
            if (cancelled) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setCount(response.body);
            } else {
                setFailure(response);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [id, reloadKey]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const tracked = useMemo(() => lookups.products.filter((product) => product.track_inventory), [lookups.products]);
    const counted = useMemo(() => Object.fromEntries((count?.lines ?? []).map((line) => [line.product_id, line])), [count]);
    const picked = lookups.productsById[productId];

    /** Handles a failed change: the count may have been posted or discarded by someone else while this page was open. */
    function fail(result, whatFailed) {
        if (result.status === 401) {
            signIn();
            return;
        }
        const code = result.body?.error?.code;
        if (code === 'STOCK_COUNT_NOT_OPEN') {
            setNotice('This count was posted or discarded by someone else, so it can no longer be changed. Showing its current state.');
            setReloadKey((key) => key + 1);
            return;
        }
        setNotice(failureMessage(result, whatFailed));
    }

    async function saveLines(lines, whatFailed) {
        setBusy(true);
        setNotice(null);
        const result = await request(`/api/v1/inventory/counts/${id}/lines`, { method: 'PUT', body: { lines } });
        setBusy(false);
        if (result.ok) {
            setCount(result.body);
            return true;
        }
        fail(result, whatFailed);
        return false;
    }

    async function addProduct(event) {
        event.preventDefault();
        if (productId === '') {
            return;
        }
        if (!validQuantity(quantity)) {
            setQuantityError('Enter what you found: zero or more, with up to 3 decimals.');
            return;
        }
        const saved = await saveLines([{ product_id: productId, counted_quantity: quantity.trim() }], 'The count could not be saved.');
        if (saved) {
            setProductId('');
            setQuantity('');
            setQuantityError(null);
            // The picker is a fresh search box again; put the cursor back so the next product can be typed straight away.
            setTimeout(() => document.getElementById('count_product')?.focus(), 0);
        }
    }

    async function editLine(line, text) {
        const next = text.trim();
        if (!validQuantity(next)) {
            setNotice('Enter what you found: zero or more, with up to 3 decimals.');
            setReloadKey((key) => key + 1);
            return;
        }
        if (Number(next) === Number(line.counted_quantity)) {
            return;
        }
        await saveLines([{ product_id: line.product_id, counted_quantity: next }], 'The change could not be saved.');
    }

    async function removeLine(line) {
        setBusy(true);
        setNotice(null);
        const result = await request(`/api/v1/inventory/counts/${id}/lines/${line.product_id}`, { method: 'DELETE' });
        setBusy(false);
        if (result.ok) {
            setCount(result.body);
        } else {
            fail(result, 'The product could not be removed.');
        }
    }

    async function post() {
        setBusy(true);
        setNotice(null);
        const result = await request(`/api/v1/inventory/counts/${id}/post`, { method: 'POST', headers: { 'Idempotency-Key': postKey.current } });
        setBusy(false);
        setConfirm(null);
        if (result.ok) {
            setCount(result.body);
            setToast(`Count posted: ${result.body.summary.lines_with_variance} ${result.body.summary.lines_with_variance === 1 ? 'product' : 'products'} adjusted.`);
            return;
        }
        const code = result.body?.error?.code;
        if (result.status === 0) {
            setNotice('The connection dropped, so the count may or may not have been posted. Press Post count again; it will not be applied twice.');
        } else if (code === 'TERMINAL_NOT_ENROLLED') {
            setNotice('This browser is not enrolled as a terminal, so it cannot post a count. An administrator can enroll it under Terminals.');
        } else if (code === 'IDEMPOTENCY_KEY_REUSED') {
            postKey.current = crypto.randomUUID();
            setNotice('That request was already used for something different. Review the count and post it again.');
        } else if (result.status === 422) {
            setNotice('Add at least one counted product before posting.');
        } else {
            fail(result, 'The count could not be posted.');
        }
    }

    async function cancelCount() {
        setBusy(true);
        setNotice(null);
        const result = await request(`/api/v1/inventory/counts/${id}/cancel`, { method: 'POST' });
        setBusy(false);
        setConfirm(null);
        if (result.ok) {
            setCount(result.body);
            setToast('Count discarded. Stock was not changed.');
        } else {
            fail(result, 'The count could not be discarded.');
        }
    }

    const summary = count?.summary;
    const lines = count?.lines ?? [];
    const differ = summary?.lines_with_variance ?? 0;
    const postDisabled = busy || lines.length === 0 || terminal.state === 'not-enrolled';

    return (
        <AdminLayout title="Stock count" requiredCapability="STOCK_ADJUST" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <p className="mb-2 text-xs">
                <Link to="/admin/inventory/counts" className="text-emerald-400 hover:underline">
                    &larr; All counts
                </Link>
            </p>

            {failure && !count && (
                <ErrorAlert
                    message={failure.status === 404 ? 'This stock count was not found.' : failureMessage(failure, 'The count could not be loaded.')}
                    onRetry={failure.status === 404 || failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !count && !failure && <div className="h-32 animate-pulse rounded bg-slate-900" aria-busy="true" aria-label="Loading count" />}

            {count && (
                <>
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="text-xl font-semibold text-slate-100">{count.location_name}</h2>
                                <CountStatusBadge status={count.status} />
                            </div>
                            <p className="mt-1 text-sm text-slate-400">
                                Started {formatDateTime(count.created_at)}
                                {count.posted_at && <> &middot; posted {formatDateTime(count.posted_at)}</>}
                                {count.cancelled_at && <> &middot; cancelled {formatDateTime(count.cancelled_at)}</>}
                            </p>
                            {count.note && <p className="mt-1 break-words text-sm text-slate-300">{count.note}</p>}
                        </div>
                        {open && (
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={postDisabled}
                                    onClick={() => setConfirm('post')}
                                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                                >
                                    Post count
                                </button>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => setConfirm('cancel')}
                                    className="min-h-11 rounded-md border border-slate-600 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 disabled:opacity-40 lg:min-h-0"
                                >
                                    Discard count
                                </button>
                            </div>
                        )}
                    </div>

                    {open && <TerminalNotice terminal={terminal} canManageTerminals={canManageTerminals} doing="post a count" />}

                    {notice && (
                        <p role="alert" className="mb-3 rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                            {notice}
                        </p>
                    )}
                    {lookups.failed && !lookups.loading && <ErrorAlert message="Product names could not be loaded, so you cannot add products yet." onRetry={lookups.retry} />}

                    {open && (
                        <form onSubmit={addProduct} noValidate className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-3">
                            <label htmlFor="count_product" className="mb-1 block text-xs text-slate-400">
                                Add a product you counted
                            </label>
                            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                                <ProductPicker id="count_product" products={tracked} value={productId} onChange={(value) => { setProductId(value); setQuantityError(null); }} />
                                {productId !== '' && (
                                    <div className="md:w-56">
                                        <label htmlFor="count_quantity" className="mb-1 block text-xs text-slate-400">
                                            Found{picked ? ` (${picked.unit_of_measure})` : ''}
                                        </label>
                                        <div className="flex gap-2">
                                            <input
                                                id="count_quantity"
                                                type="text"
                                                inputMode="decimal"
                                                autoComplete="off"
                                                autoFocus
                                                value={quantity}
                                                aria-invalid={Boolean(quantityError)}
                                                onChange={(event) => {
                                                    setQuantity(event.target.value);
                                                    setQuantityError(null);
                                                }}
                                                className={`${inputClass(quantityError)} w-full`}
                                            />
                                            <button
                                                type="submit"
                                                disabled={busy}
                                                className="min-h-11 shrink-0 rounded-md bg-emerald-500 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                                            >
                                                Add
                                            </button>
                                        </div>
                                        {quantityError && (
                                            <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                                                &#9888; {quantityError}
                                            </p>
                                        )}
                                        {!quantityError && counted[productId] && (
                                            <p className="mt-1 text-[11px] text-amber-400">Already counted as {quantityText(counted[productId].counted_quantity)}; adding replaces it.</p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </form>
                    )}

                    {lines.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                            <p className="text-sm text-slate-200">{open ? 'Nothing counted yet.' : 'No products were counted.'}</p>
                            {open && <p className="mt-1 text-xs text-slate-500">Add each product you counted. Products you leave out are not changed.</p>}
                        </div>
                    ) : (
                        <>
                            <p className="mb-2 text-sm text-slate-300" aria-live="polite">
                                {summary.lines_counted} {summary.lines_counted === 1 ? 'product' : 'products'} counted, {differ} {differ === 1 ? 'differs' : 'differ'} from the system
                                {differ > 0 && (
                                    <span className="text-slate-400">
                                        {' '}
                                        (<span className="text-emerald-400">{quantityText(summary.units_over)}</span> found extra,{' '}
                                        <span className="text-amber-400">{quantityText(summary.units_short)}</span> missing)
                                    </span>
                                )}
                                .
                            </p>

                            <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                                <table className="w-full min-w-max text-left text-sm">
                                    <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="border-b border-slate-800 px-3 py-2 font-mono">Product</th>
                                            <th className="border-b border-slate-800 px-3 py-2 text-right font-mono">System expected</th>
                                            <th className="border-b border-slate-800 px-3 py-2 text-right font-mono">Found</th>
                                            <th className="border-b border-slate-800 px-3 py-2 text-right font-mono">Difference</th>
                                            <th className="border-b border-slate-800 px-3 py-2" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-800/70">
                                        {lines.map((line) => (
                                            <tr key={line.product_id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                                <td className="px-3 py-2">
                                                    <span className="text-slate-100">{line.product_name}</span>
                                                    <span className="ml-2 font-mono text-[11px] text-slate-500">{line.sku}</span>
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-400">{quantityText(line.expected_quantity)}</td>
                                                <td className="px-3 py-2 text-right">
                                                    {open ? (
                                                        <input
                                                            key={`${line.product_id}:${line.counted_quantity}`}
                                                            aria-label={`Found for ${line.product_name}`}
                                                            type="text"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            defaultValue={plainQuantity(line.counted_quantity)}
                                                            disabled={busy}
                                                            onBlur={(event) => editLine(line, event.target.value)}
                                                            onKeyDown={(event) => event.key === 'Enter' && event.currentTarget.blur()}
                                                            className={`${inputClass(false)} w-28`}
                                                        />
                                                    ) : (
                                                        <span className="font-mono tabular-nums text-slate-100">{quantityText(line.counted_quantity)}</span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    <Variance value={line.variance} />
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {open && (
                                                        <button
                                                            type="button"
                                                            disabled={busy}
                                                            onClick={() => removeLine(line)}
                                                            aria-label={`Remove ${line.product_name}`}
                                                            className="min-h-11 px-2 text-xs text-slate-500 hover:text-rose-400 disabled:opacity-40 lg:min-h-0"
                                                        >
                                                            Remove
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <ul className="space-y-3 md:hidden" aria-label="Counted products">
                                {lines.map((line) => (
                                    <li key={line.product_id} className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                                        <p className="break-words text-sm text-slate-100">{line.product_name}</p>
                                        <p className="font-mono text-[11px] text-slate-500">{line.sku}</p>
                                        <dl className="mt-2 grid grid-cols-3 gap-2 text-xs">
                                            <div>
                                                <dt className="text-slate-500">Expected</dt>
                                                <dd className="font-mono tabular-nums text-slate-300">{quantityText(line.expected_quantity)}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-500">Found</dt>
                                                <dd>
                                                    {open ? (
                                                        <input
                                                            key={`${line.product_id}:${line.counted_quantity}`}
                                                            aria-label={`Found for ${line.product_name}`}
                                                            type="text"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            defaultValue={plainQuantity(line.counted_quantity)}
                                                            disabled={busy}
                                                            onBlur={(event) => editLine(line, event.target.value)}
                                                            onKeyDown={(event) => event.key === 'Enter' && event.currentTarget.blur()}
                                                            className={`${inputClass(false)} w-full`}
                                                        />
                                                    ) : (
                                                        <span className="font-mono tabular-nums text-slate-100">{quantityText(line.counted_quantity)}</span>
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-slate-500">Difference</dt>
                                                <dd>
                                                    <Variance value={line.variance} />
                                                </dd>
                                            </div>
                                        </dl>
                                        {open && (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => removeLine(line)}
                                                className="mt-1 min-h-11 text-xs text-slate-500 hover:text-rose-400 disabled:opacity-40"
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>

                            {open && (
                                <p className="mt-3 text-xs text-slate-500">
                                    Posting corrects only these products, by the difference shown. Sales made since a product was counted stay counted, and products not listed here are left as they are.
                                </p>
                            )}
                        </>
                    )}
                </>
            )}

            {confirm === 'post' && (
                <ConfirmDialog
                    title="Post this count?"
                    body={`This changes the recorded stock for ${differ} ${differ === 1 ? 'product' : 'products'} to match what you found, and it cannot be undone. If it turns out to be wrong, correct it with another count or an adjustment.`}
                    confirmLabel="Post count"
                    busy={busy}
                    onConfirm={post}
                    onCancel={() => setConfirm(null)}
                />
            )}
            {confirm === 'cancel' && (
                <ConfirmDialog
                    title="Discard this count?"
                    body="The counted quantities are thrown away and stock is not changed. You can start a new count of this location afterwards."
                    confirmLabel="Discard count"
                    busy={busy}
                    onConfirm={cancelCount}
                    onCancel={() => setConfirm(null)}
                />
            )}
        </AdminLayout>
    );
}

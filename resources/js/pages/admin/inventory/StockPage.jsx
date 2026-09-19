import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import EntityCombobox from '../reports/EntityCombobox';
import { formatDateTime } from '../reports/formatters';
import StockActionPanel from './StockActionPanel';
import { toThousandths, quantityText, useInventoryLookups } from './inventoryParts';

const PER_PAGE = 25;
const VIEWS = [
    { id: 'all', label: 'All stock', path: '/api/v1/inventory/stock' },
    { id: 'low', label: 'Low stock', path: '/api/v1/inventory/low-stock' },
];

function OnHand({ row, reorderLevel }) {
    const negative = toThousandths(row.quantity_on_hand) < 0;
    const low = !negative && toThousandths(row.quantity_on_hand) <= toThousandths(reorderLevel ?? '0');
    return (
        <span className="inline-flex items-center gap-2">
            <span className={`font-mono tabular-nums ${negative ? 'text-amber-400' : 'text-slate-100'}`}>{quantityText(row.quantity_on_hand)}</span>
            {negative && <span className="rounded border border-amber-700/70 px-1 py-0.5 font-mono text-[10px] text-amber-400">NEGATIVE</span>}
            {low && <span className="rounded border border-rose-800 px-1 py-0.5 font-mono text-[10px] text-rose-400">LOW</span>}
        </span>
    );
}

/**
 * /admin/inventory/stock -- inventoryStockList / inventoryLowStockList (session reads, any store user)
 * plus the two writes in slide-overs, which need an enrolled terminal on top of STOCK_ADJUST: the
 * balance is recorded against that terminal and the store's default location. Untracked products have
 * no stock and are not listed. Ids are shown as names using the product and location lists.
 */
export default function StockPage() {
    const { user, setUser } = useAuth();
    const lookups = useInventoryLookups();
    const [view, setView] = useState('all');
    const [productId, setProductId] = useState('');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [terminal, setTerminal] = useState({ state: 'checking' }); // checking | enrolled | not-enrolled | unknown
    const [panel, setPanel] = useState(null); // null | { mode, productId? }
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanel = useCallback(() => setPanel(null), []);

    useEffect(() => {
        request('/api/v1/terminal/current').then((response) => {
            if (response.ok) {
                setTerminal({ state: 'enrolled', code: response.body.terminal_code });
            } else if (response.status === 403 && response.body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
                setTerminal({ state: 'not-enrolled' });
            } else {
                setTerminal({ state: 'unknown' });
            }
        });
    }, []);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (productId) {
            params.set('product_id', productId);
        }
        setLoading(true);
        setFailure(null);
        request(`${VIEWS.find((option) => option.id === view).path}?${params.toString()}`).then((response) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setResult(response.body);
            } else {
                setResult(null);
                setFailure(response);
            }
        });
    }, [view, productId, page, reloadKey]);

    function reload() {
        setReloadKey((key) => key + 1);
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    function done(movement, product) {
        setPanel(null);
        setToast(`${product?.name ?? 'Stock'}: ${panelSummary(movement.movement_type, quantityText(movement.quantity))}`);
        reload();
    }

    const tracked = useMemo(() => lookups.products.filter((product) => product.track_inventory && product.active), [lookups.products]);
    const options = useMemo(
        () => lookups.products.filter((product) => product.track_inventory).map((product) => ({ value: product.id, code: product.sku, name: product.name })),
        [lookups.products],
    );
    const defaultLocation = lookups.locations.find((location) => location.is_default);
    const canRecord = terminal.state === 'enrolled' || terminal.state === 'unknown';
    const rows = result?.data ?? [];
    const canManageTerminals = user.capabilities.includes('TERMINAL_MANAGE');

    const nameOf = (id) => lookups.productsById[id]?.name ?? '(unknown product)';
    const locationOf = (id) => lookups.locationsById[id]?.name ?? '—';

    const rowActions = (row) =>
        defaultLocation && row.location_id !== defaultLocation.id ? (
            <p className="text-right text-[11px] text-slate-600" title={`Stock changes are recorded at ${defaultLocation.name}.`}>
                Not the default location
            </p>
        ) : (
            <div className="flex items-center justify-end gap-3 whitespace-nowrap text-xs">
                <button
                    type="button"
                    disabled={!canRecord}
                    onClick={() => setPanel({ mode: 'receipt', productId: row.product_id })}
                    className="min-h-11 text-emerald-400 hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:no-underline lg:min-h-0"
                >
                    Receive
                </button>
                <button
                    type="button"
                    disabled={!canRecord}
                    onClick={() => setPanel({ mode: 'adjustment', productId: row.product_id })}
                    className="min-h-11 text-slate-400 hover:text-emerald-400 hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:no-underline lg:min-h-0"
                >
                    Adjust
                </button>
            </div>
        );

    return (
        <AdminLayout title="Stock" requiredCapability="STOCK_ADJUST" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Stock</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        What is on hand for each product you track. Sales lower it automatically; record deliveries and corrections here so the count stays true.
                        {defaultLocation && ` Changes you record are added to ${defaultLocation.name}, your default location.`}
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        disabled={!canRecord}
                        onClick={() => setPanel({ mode: 'receipt' })}
                        className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                    >
                        Receive stock
                    </button>
                    <button
                        type="button"
                        disabled={!canRecord}
                        onClick={() => setPanel({ mode: 'adjustment' })}
                        className="min-h-11 rounded-md border border-slate-600 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                    >
                        Adjust stock
                    </button>
                </div>
            </div>

            {terminal.state === 'not-enrolled' && (
                <div role="status" className="mb-3 rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-200">
                    This browser is not enrolled as a terminal, so it can view stock but not record changes.{' '}
                    {canManageTerminals ? (
                        <Link to="/terminals" className="text-emerald-400 underline">
                            Enroll this browser
                        </Link>
                    ) : (
                        'Ask an administrator to enroll it.'
                    )}
                </div>
            )}

            {lookups.failed && !lookups.loading && (
                <ErrorAlert message="Product and location names could not be loaded, so some rows show placeholders." onRetry={lookups.retry} />
            )}

            <div className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:items-end">
                <div role="group" aria-label="View">
                    <span className="mb-1 block text-xs text-slate-500">Show</span>
                    <div className="flex overflow-hidden rounded-md border border-slate-700">
                        {VIEWS.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                aria-pressed={view === option.id}
                                onClick={() => {
                                    setView(option.id);
                                    setPage(1);
                                }}
                                className={`min-h-11 flex-1 px-3 py-1.5 text-xs md:flex-none lg:min-h-0 ${
                                    view === option.id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="md:w-72">
                    <EntityCombobox
                        id="stock_product_filter"
                        label="Product"
                        noun="products"
                        searchPlaceholder="Search products"
                        options={options}
                        value={productId}
                        loading={lookups.loading}
                        onChange={(value) => {
                            setProductId(value);
                            setPage(1);
                        }}
                    />
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The stock could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading stock">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    {view === 'low' ? (
                        <p className="text-sm text-slate-200">Nothing is at or below its reorder level.</p>
                    ) : productId ? (
                        <>
                            <p className="text-sm text-slate-200">No stock has been recorded for this product yet.</p>
                            <button type="button" onClick={() => setProductId('')} className="mt-2 text-xs text-emerald-400 underline">
                                Show all products
                            </button>
                        </>
                    ) : (
                        <>
                            <p className="text-sm text-slate-200">No stock has been recorded yet.</p>
                            <p className="mt-1 text-xs text-slate-500">Receive opening stock for the products you track, then sales will lower it.</p>
                        </>
                    )}
                </div>
            )}

            {result && rows.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Product', 'Location', 'On hand', 'Reorder level', 'Updated', ''].map((heading) => (
                                        <th key={heading || 'actions'} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {rows.map((row) => {
                                    const product = lookups.productsById[row.product_id];
                                    return (
                                        <tr key={`${row.product_id}:${row.location_id}`} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                            <td className="px-3 py-2">
                                                <span className="text-slate-100">{nameOf(row.product_id)}</span>
                                                {product && <span className="ml-2 font-mono text-[11px] text-slate-500">{product.sku}</span>}
                                            </td>
                                            <td className="px-3 py-2 text-slate-300">{locationOf(row.location_id)}</td>
                                            <td className="px-3 py-2">
                                                <OnHand row={row} reorderLevel={product?.reorder_level} />
                                                {product && <span className="ml-1 text-[11px] text-slate-500">{product.unit_of_measure}</span>}
                                            </td>
                                            <td className="px-3 py-2 font-mono tabular-nums text-slate-400">{product ? quantityText(product.reorder_level) : '—'}</td>
                                            <td className="px-3 py-2 font-mono text-[12px] text-slate-500">{formatDateTime(row.updated_at)}</td>
                                            <td className="px-3 py-2">{rowActions(row)}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Stock">
                        {rows.map((row) => {
                            const product = lookups.productsById[row.product_id];
                            return (
                                <li key={`${row.product_id}:${row.location_id}`} className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                                    <p className="break-words text-sm text-slate-100">{nameOf(row.product_id)}</p>
                                    <p className="font-mono text-[11px] text-slate-500">
                                        {product?.sku} &middot; {locationOf(row.location_id)}
                                    </p>
                                    <div className="mt-2 flex items-center justify-between text-sm">
                                        <OnHand row={row} reorderLevel={product?.reorder_level} />
                                        <span className="text-[11px] text-slate-500">Reorder at {product ? quantityText(product.reorder_level) : '—'}</span>
                                    </div>
                                    <div className="mt-1 border-t border-slate-800 pt-1">{rowActions(row)}</div>
                                </li>
                            );
                        })}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {panel && (
                <StockActionPanel
                    key={`${panel.mode}:${panel.productId ?? 'new'}`}
                    mode={panel.mode}
                    products={tracked}
                    initialProductId={panel.productId ?? ''}
                    defaultLocation={defaultLocation}
                    onDone={done}
                    onClose={closePanel}
                    onUnauthorized={signIn}
                />
            )}
        </AdminLayout>
    );
}

function panelSummary(movementType, quantity) {
    const labels = {
        PURCHASE_RECEIPT: `received ${quantity}`,
        OPENING_STOCK: `opening stock ${quantity} recorded`,
        STOCK_ADJUSTMENT_IN: `${quantity} added`,
        STOCK_ADJUSTMENT_OUT: `${quantity} removed`,
        DAMAGE: `${quantity} written off as damaged`,
        EXPIRED: `${quantity} written off as expired`,
    };
    return labels[movementType] ?? `${quantity} recorded`;
}

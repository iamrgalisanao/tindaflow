import { useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import EntityCombobox from '../reports/EntityCombobox';
import { formatDateTime, shortId } from '../reports/formatters';
import { MOVEMENT_TYPES, MovementBadge, directionOf, quantityText, useInventoryLookups } from './inventoryParts';

const PER_PAGE = 25;

const EMPTY_FILTERS = { productId: '', movementType: '', from: '', to: '' };

/**
 * /admin/inventory/movements -- inventoryMovementList: the append-only ledger behind every balance,
 * newest first. Read-only; corrections are made by recording a new adjustment, never by editing a row.
 * Quantities are stored positive and the type carries the direction, so the sign is shown here.
 */
export default function MovementsPage() {
    const { setUser } = useAuth();
    const lookups = useInventoryLookups();
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const seq = useRef(0);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (filters.productId) {
            params.set('product_id', filters.productId);
        }
        if (filters.movementType) {
            params.set('movement_type', filters.movementType);
        }
        if (filters.from) {
            params.set('from', filters.from);
        }
        if (filters.to) {
            params.set('to', filters.to);
        }
        setLoading(true);
        setFailure(null);
        request(`/api/v1/inventory/movements?${params.toString()}`).then((response) => {
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
    }, [filters, page, reloadKey]);

    function change(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const options = useMemo(() => lookups.products.map((product) => ({ value: product.id, code: product.sku, name: product.name })), [lookups.products]);
    const rows = result?.data ?? [];
    const dateRangeInvalid = filters.from !== '' && filters.to !== '' && filters.from > filters.to;
    const filtered = Object.values(filters).some((value) => value !== '');

    const nameOf = (id) => lookups.productsById[id]?.name ?? `Product ${shortId(id)}`;
    const locationOf = (id) => lookups.locationsById[id]?.name ?? '—';
    const quantityOf = (row) => `${directionOf(row.movement_type) > 0 ? '+' : '-'}${quantityText(row.quantity)}`;
    const quantityTone = (row) => (directionOf(row.movement_type) > 0 ? 'text-emerald-400' : 'text-amber-400');

    return (
        <AdminLayout title="Movements" requiredCapability="STOCK_ADJUST" wide>
            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Stock movements</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Every change to stock, newest first. Rows are never edited; to correct a mistake, record an adjustment.
                </p>
            </div>

            <div className="mb-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:grid-cols-2 lg:grid-cols-4">
                <EntityCombobox
                    id="movement_product_filter"
                    label="Product"
                    noun="products"
                    searchPlaceholder="Search products"
                    options={options}
                    value={filters.productId}
                    loading={lookups.loading}
                    onChange={(value) => change({ productId: value })}
                />
                <div>
                    <label htmlFor="movement_type_filter" className="mb-1 block text-xs text-slate-500">
                        Type
                    </label>
                    <select
                        id="movement_type_filter"
                        value={filters.movementType}
                        onChange={(event) => change({ movementType: event.target.value })}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 lg:min-h-0"
                    >
                        <option value="">All types</option>
                        {MOVEMENT_TYPES.map((type) => (
                            <option key={type.id} value={type.id}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="movement_from" className="mb-1 block text-xs text-slate-500">
                        From
                    </label>
                    <input
                        id="movement_from"
                        type="date"
                        value={filters.from}
                        onChange={(event) => change({ from: event.target.value })}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 [color-scheme:dark] lg:min-h-0"
                    />
                </div>
                <div>
                    <label htmlFor="movement_to" className="mb-1 block text-xs text-slate-500">
                        To
                    </label>
                    <input
                        id="movement_to"
                        type="date"
                        value={filters.to}
                        aria-invalid={dateRangeInvalid}
                        onChange={(event) => change({ to: event.target.value })}
                        className={`min-h-11 w-full rounded-md border bg-slate-950 px-2 py-1.5 text-sm text-slate-100 [color-scheme:dark] lg:min-h-0 ${
                            dateRangeInvalid ? 'border-rose-500' : 'border-slate-700'
                        }`}
                    />
                    {dateRangeInvalid && (
                        <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                            &#9888; The end date is before the start date.
                        </p>
                    )}
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The movements could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading movements">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{filtered ? 'No movements match these filters.' : 'No stock movements yet.'}</p>
                    {filtered && (
                        <button type="button" onClick={() => change(EMPTY_FILTERS)} className="mt-2 text-xs text-emerald-400 underline">
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {result && rows.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['When', 'Product', 'Type', 'Quantity', 'Location', 'Reason', 'By'].map((heading) => (
                                        <th key={heading} className={`border-b border-slate-800 px-3 py-2 font-mono ${heading === 'Quantity' ? 'text-right' : ''}`}>
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {rows.map((row) => (
                                    <tr key={row.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="whitespace-nowrap px-3 py-2 font-mono text-[12px] text-slate-400">{formatDateTime(row.occurred_at)}</td>
                                        <td className="px-3 py-2 text-slate-100">{nameOf(row.product_id)}</td>
                                        <td className="px-3 py-2">
                                            <MovementBadge movementType={row.movement_type} />
                                        </td>
                                        <td className={`px-3 py-2 text-right font-mono tabular-nums ${quantityTone(row)}`}>{quantityOf(row)}</td>
                                        <td className="px-3 py-2 text-slate-300">{locationOf(row.location_id)}</td>
                                        <td className="max-w-xs px-3 py-2 text-slate-300">{row.reason ?? <span className="text-slate-600">—</span>}</td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-500" title={row.created_by}>
                                            {shortId(row.created_by)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Stock movements">
                        {rows.map((row) => (
                            <li key={row.id} className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="min-w-0 break-words text-sm text-slate-100">{nameOf(row.product_id)}</p>
                                    <span className={`shrink-0 font-mono text-sm tabular-nums ${quantityTone(row)}`}>{quantityOf(row)}</span>
                                </div>
                                <div className="mt-1 flex items-center justify-between gap-2">
                                    <MovementBadge movementType={row.movement_type} />
                                    <span className="font-mono text-[11px] text-slate-500">{formatDateTime(row.occurred_at)}</span>
                                </div>
                                {row.reason && <p className="mt-2 break-words text-xs text-slate-400">{row.reason}</p>}
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}
        </AdminLayout>
    );
}

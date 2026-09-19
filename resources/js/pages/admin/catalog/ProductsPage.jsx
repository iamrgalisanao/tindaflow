import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { formatMoney } from '../reports/formatters';
import { ActiveBadge, ConfirmDialog, ErrorAlert, Pager, TaxBadge, Toast } from './CatalogParts';
import ProductFormPanel from './ProductFormPanel';
import { RETURN_KEY, failureMessage, fetchAll, request } from './catalogApi';

const PER_PAGE = 25;
const STATUSES = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active' },
    { id: 'inactive', label: 'Inactive' },
];
const SORTS = [
    { id: 'name', label: 'Name A–Z' },
    { id: '-name', label: 'Name Z–A' },
    { id: 'sku', label: 'SKU' },
    { id: '-created_at', label: 'Newest' },
];
const DEFAULT_FILTERS = { search: '', categoryId: '', status: 'active', sort: 'name' };

/**
 * /admin/catalog/products -- productList (server-side filter/sort/pagination), productCreate/Update in
 * a slide-over, productActivate/Deactivate. Products are never deleted; deactivating retires them.
 */
export default function ProductsPage() {
    const { setUser } = useAuth();
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [searchInput, setSearchInput] = useState('');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [categories, setCategories] = useState([]);
    const [brands, setBrands] = useState([]);
    const [panel, setPanel] = useState(null); // null | { product?: object }
    const [confirm, setConfirm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanel = useCallback(() => setPanel(null), []);

    const categoryName = useMemo(() => new Map(categories.map((category) => [category.id, category.name])), [categories]);
    const brandName = useMemo(() => new Map(brands.map((brand) => [brand.id, brand.name])), [brands]);

    useEffect(() => {
        const timer = setTimeout(() => {
            setFilters((prior) => (prior.search === searchInput.trim() ? prior : { ...prior, search: searchInput.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        fetchAll('/api/v1/categories').then((all) => all.ok && setCategories(all.rows));
        fetchAll('/api/v1/brands').then((all) => all.ok && setBrands(all.rows));
    }, []);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page), sort: filters.sort });
        if (filters.search) {
            params.set('search', filters.search);
        }
        if (filters.categoryId) {
            params.set('category_id', filters.categoryId);
        }
        if (filters.status !== 'all') {
            params.set('active', filters.status === 'active' ? '1' : '0');
        }
        setLoading(true);
        setFailure(null);
        request(`/api/v1/products?${params.toString()}`).then((response) => {
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

    function reload() {
        setReloadKey((key) => key + 1);
    }

    function changeFilter(patch) {
        setFilters((prior) => ({ ...prior, ...patch }));
        setPage(1);
    }

    function clearFilters() {
        setSearchInput('');
        setFilters(DEFAULT_FILTERS);
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

    async function setActive(product, active) {
        setBusy(true);
        const response = await request(`/api/v1/products/${product.id}/${active ? 'activate' : 'deactivate'}`, { method: 'POST' });
        setBusy(false);
        setConfirm(null);
        if (response.status === 401) {
            signIn();
            return;
        }
        if (!response.ok) {
            setFailure(response);
            return;
        }
        setPanel(null);
        setToast(active ? `${product.name} reactivated` : `${product.name} deactivated`);
        reload();
    }

    function saved(product, wasEditing) {
        setPanel(null);
        setToast(wasEditing ? 'Product saved' : 'Product created');
        reload();
    }

    const filtersAreDefault = filters.search === '' && filters.categoryId === '' && filters.status === 'active';
    const products = result?.data ?? [];
    const rowActions = (product) => (
        <div className="flex items-center justify-end gap-3 whitespace-nowrap text-xs">
            <button type="button" onClick={() => setPanel({ product })} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                Edit
            </button>
            {product.active ? (
                <button type="button" onClick={() => setConfirm(product)} className="min-h-11 text-slate-400 hover:text-rose-400 hover:underline lg:min-h-0">
                    Deactivate
                </button>
            ) : (
                <button type="button" onClick={() => setActive(product, true)} className="min-h-11 text-slate-400 hover:text-emerald-400 hover:underline lg:min-h-0">
                    Activate
                </button>
            )}
        </div>
    );

    return (
        <AdminLayout title="Catalog / Products" requiredCapability="CATALOG_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Products</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        The items your store sells. Deactivating a product stops it being sold but keeps it in past sales.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setPanel({})}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                >
                    New product
                </button>
            </div>

            <div className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:flex-wrap md:items-end">
                <div className="md:w-72">
                    <label htmlFor="product_search" className="mb-1 block text-xs text-slate-500">
                        Search
                    </label>
                    <input
                        id="product_search"
                        type="search"
                        value={searchInput}
                        placeholder="Search name or SKU"
                        onChange={(event) => setSearchInput(event.target.value)}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:border-emerald-500 focus:outline-none lg:min-h-0"
                    />
                </div>
                <div>
                    <label htmlFor="product_category_filter" className="mb-1 block text-xs text-slate-500">
                        Category
                    </label>
                    <select
                        id="product_category_filter"
                        value={filters.categoryId}
                        onChange={(event) => changeFilter({ categoryId: event.target.value })}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 md:w-48 lg:min-h-0"
                    >
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div role="group" aria-label="Status">
                    <span className="mb-1 block text-xs text-slate-500">Status</span>
                    <div className="flex overflow-hidden rounded-md border border-slate-700">
                        {STATUSES.map((status) => (
                            <button
                                key={status.id}
                                type="button"
                                aria-pressed={filters.status === status.id}
                                onClick={() => changeFilter({ status: status.id })}
                                className={`min-h-11 flex-1 px-3 py-1.5 text-xs md:flex-none lg:min-h-0 ${
                                    filters.status === status.id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'
                                }`}
                            >
                                {status.label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="md:ml-auto">
                    <label htmlFor="product_sort" className="mb-1 block text-xs text-slate-500">
                        Sort
                    </label>
                    <select
                        id="product_sort"
                        value={filters.sort}
                        onChange={(event) => changeFilter({ sort: event.target.value })}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 md:w-40 lg:min-h-0"
                    >
                        {SORTS.map((sort) => (
                            <option key={sort.id} value={sort.id}>
                                {sort.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The products could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading products">
                    {[0, 1, 2, 3, 4].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && products.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    {filtersAreDefault ? (
                        <>
                            <p className="text-sm text-slate-200">No active products yet. Create your first product.</p>
                            <button
                                type="button"
                                onClick={() => setPanel({})}
                                className="mt-3 min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                            >
                                New product
                            </button>
                        </>
                    ) : (
                        <>
                            <p className="text-sm text-slate-200">No products match these filters.</p>
                            <button type="button" onClick={clearFilters} className="mt-2 text-xs text-emerald-400 underline">
                                Clear filters
                            </button>
                        </>
                    )}
                </div>
            )}

            {result && products.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['SKU', 'Product', 'Category', 'Brand', 'Unit', 'Cost', 'Price', 'Tax class', 'Status', ''].map((heading, index) => (
                                        <th
                                            key={heading || 'actions'}
                                            className={`border-b border-slate-800 px-3 py-2 font-mono ${['Cost', 'Price'].includes(heading) ? 'text-right' : ''} ${
                                                index === 0 ? 'sticky left-0 z-10 bg-slate-950' : ''
                                            }`}
                                        >
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {products.map((product) => (
                                    <tr key={product.id} className={`group odd:bg-slate-950/40 hover:bg-slate-900 ${product.active ? '' : 'text-slate-500'}`}>
                                        <td className="sticky left-0 z-[1] bg-slate-950 px-3 py-2 group-hover:bg-slate-900">
                                            <span className="font-mono text-[12px] text-emerald-400">{product.sku}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`block ${product.active ? 'text-slate-100' : 'text-slate-500'}`}>{product.name}</span>
                                            <span className="block font-mono text-[11px] text-slate-500">{product.barcode ?? 'No barcode'}</span>
                                        </td>
                                        <td className="px-3 py-2 text-slate-300">{categoryName.get(product.category_id) ?? <span className="text-slate-600">—</span>}</td>
                                        <td className="px-3 py-2 text-slate-300">{brandName.get(product.brand_id) ?? <span className="text-slate-600">—</span>}</td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-300">{product.unit_of_measure}</td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-300">
                                            {product.cost === null ? <span className="text-slate-600">—</span> : formatMoney(product.cost)}
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono font-semibold tabular-nums text-slate-100">{formatMoney(product.selling_price)}</td>
                                        <td className="px-3 py-2">
                                            <TaxBadge taxClass={product.tax_class} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <ActiveBadge active={product.active} />
                                        </td>
                                        <td className="px-3 py-2">{rowActions(product)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Products">
                        {products.map((product) => (
                            <li key={product.id} className={`rounded-lg border border-slate-800 bg-slate-900 p-3 ${product.active ? '' : 'opacity-70'}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-mono text-[12px] text-emerald-400">{product.sku}</p>
                                        <p className="break-words text-sm text-slate-100">{product.name}</p>
                                        <p className="font-mono text-[11px] text-slate-500">{product.barcode ?? 'No barcode'}</p>
                                    </div>
                                    <ActiveBadge active={product.active} />
                                </div>
                                <dl className="mt-2 space-y-1 text-sm">
                                    <div className="flex justify-between text-slate-400">
                                        <dt>Category</dt>
                                        <dd className="text-slate-200">{categoryName.get(product.category_id) ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between text-slate-400">
                                        <dt>Tax class</dt>
                                        <dd>
                                            <TaxBadge taxClass={product.tax_class} />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between border-t border-slate-800 pt-1 font-semibold text-slate-100">
                                        <dt>Price</dt>
                                        <dd className="font-mono tabular-nums">{formatMoney(product.selling_price)}</dd>
                                    </div>
                                </dl>
                                <div className="mt-1 border-t border-slate-800 pt-1">{rowActions(product)}</div>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {panel && (
                <ProductFormPanel
                    key={panel.product?.id ?? 'new'}
                    product={panel.product}
                    categories={categories}
                    brands={brands}
                    onCategoryCreated={(created) => setCategories((prior) => [...prior, created].sort((a, b) => a.name.localeCompare(b.name)))}
                    onBrandCreated={(created) => setBrands((prior) => [...prior, created].sort((a, b) => a.name.localeCompare(b.name)))}
                    onSaved={saved}
                    onRequestDeactivate={(product) => setConfirm(product)}
                    onActivate={(product) => setActive(product, true)}
                    onClose={closePanel}
                    onUnauthorized={signIn}
                />
            )}

            {confirm && (
                <ConfirmDialog
                    title={`Deactivate ${confirm.name}?`}
                    body="It can no longer be sold until you reactivate it. Past sales keep the product exactly as it was sold."
                    confirmLabel="Deactivate"
                    busy={busy}
                    onConfirm={() => setActive(confirm, false)}
                    onCancel={() => setConfirm(null)}
                />
            )}
        </AdminLayout>
    );
}

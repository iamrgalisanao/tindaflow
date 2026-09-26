import { useEffect, useState } from 'react';
import { apiFetch } from '../../api';

const PAGE_SIZE = 100;

/**
 * Where the cashier finds what to sell: the scan/search box, the category tabs, and a grid of tap-to-add product tiles.
 * The scan box keeps focus and behaves exactly as before (a scanned barcode adds the product; anything else is a name or
 * SKU search). A search replaces the grid with its results until it is cleared.
 */
export default function CatalogPanel({ search, onSearchChange, onSearch, onClearSearch, searchRef, scanNotice, results, onAdd }) {
    const [categories, setCategories] = useState([]);
    const [categoryId, setCategoryId] = useState(''); // '' = all
    const [tiles, setTiles] = useState(null); // null = loading
    const [loadFailed, setLoadFailed] = useState(false);
    const [total, setTotal] = useState(0);

    useEffect(() => {
        let cancelled = false;
        apiFetch(`/api/v1/categories?per_page=${PAGE_SIZE}`).then((response) => {
            if (!cancelled && response.ok) {
                setCategories(response.body.data);
            }
        });
        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        let cancelled = false;
        setTiles(null);
        setLoadFailed(false);
        const filter = categoryId === '' ? '' : `&category_id=${encodeURIComponent(categoryId)}`;
        apiFetch(`/api/v1/products?active=1&per_page=${PAGE_SIZE}${filter}`).then((response) => {
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setTiles(response.body.data);
                setTotal(response.body.meta.total);
            } else {
                setTiles([]);
                setLoadFailed(true);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [categoryId]);

    const categoryName = (id) => categories.find((category) => category.id === id)?.name ?? '';

    const searching = results !== null;
    const shown = searching ? results : tiles;

    return (
        <section aria-label="Products" className="flex min-h-0 flex-1 flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3">
            <form onSubmit={onSearch} className="flex gap-2">
                <div className="relative flex-1">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400">
                        <path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M8 8v8M12 8v8M16 8v8" />
                    </svg>
                    <input
                        ref={searchRef}
                        type="text"
                        autoFocus
                        autoComplete="off"
                        value={search}
                        onChange={(event) => onSearchChange(event.target.value)}
                        placeholder="Scan a barcode, or search by name or SKU"
                        aria-label="Scan a barcode or search products"
                        className="min-h-12 w-full rounded border border-slate-700 bg-slate-950 pl-10 pr-3 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    />
                </div>
                <button
                    type="submit"
                    className="min-h-12 rounded border border-slate-700 bg-slate-800 px-4 font-mono text-xs font-bold uppercase tracking-widest text-slate-200 hover:bg-slate-700"
                >
                    Search
                </button>
            </form>

            {scanNotice && (
                <p
                    role="status"
                    className={`rounded px-3 py-2 text-sm ${scanNotice.kind === 'added' ? 'bg-emerald-500/10 text-emerald-300' : 'bg-rose-500/10 text-rose-300'}`}
                >
                    {scanNotice.text}
                </p>
            )}

            {searching ? (
                <div className="flex items-center justify-between">
                    <p className="text-xs text-slate-400">
                        {results.length} {results.length === 1 ? 'result' : 'results'}
                    </p>
                    <button type="button" onClick={onClearSearch} className="min-h-11 px-2 text-sm text-slate-400 underline">
                        Back to all products
                    </button>
                </div>
            ) : (
                <div role="tablist" aria-label="Categories" className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
                    {[{ id: '', name: 'All' }, ...categories].map((category) => (
                        <button
                            key={category.id || 'all'}
                            type="button"
                            role="tab"
                            aria-selected={categoryId === category.id}
                            onClick={() => setCategoryId(category.id)}
                            className={`flex min-h-11 shrink-0 items-center gap-2 rounded border px-4 font-mono text-[11px] font-bold uppercase tracking-widest ${
                                categoryId === category.id ? 'border-emerald-500 bg-emerald-500 text-slate-950' : 'border-slate-800 bg-slate-950/60 text-slate-400 hover:bg-slate-800'
                            }`}
                        >
                            {category.name}
                            {categoryId === category.id && shown !== null && (
                                <span className="rounded bg-slate-950/25 px-1.5 py-0.5 text-[10px]">{total}</span>
                            )}
                        </button>
                    ))}
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto">
                {shown === null && <p className="py-8 text-center text-sm text-slate-400">Loading products…</p>}
                {loadFailed && !searching && (
                    <p role="alert" className="rounded bg-rose-500/10 px-3 py-2 text-sm text-rose-300">
                        The products could not be loaded. You can still scan a barcode or search.
                    </p>
                )}
                {shown !== null && shown.length === 0 && !loadFailed && (
                    <p className="py-8 text-center text-sm text-slate-400">{searching ? 'No products match.' : 'No products in this category.'}</p>
                )}
                {shown !== null && shown.length > 0 && (
                    <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                        {shown.map((product) => (
                            <li key={product.id}>
                                <button
                                    type="button"
                                    onClick={() => onAdd(product)}
                                    className="flex min-h-28 w-full flex-col rounded-lg border border-slate-800 border-b-2 border-b-slate-950 bg-slate-950/60 p-3 text-left hover:border-emerald-500/60 hover:bg-emerald-500/5 active:bg-emerald-500/10"
                                >
                                    <span className="truncate font-mono text-[11px] uppercase tracking-wider text-slate-400">{product.sku}</span>
                                    <span className="mt-1 line-clamp-2 text-sm font-medium text-slate-100">{product.name}</span>
                                    <span className="mt-0.5 truncate text-xs text-slate-400">{categoryName(product.category_id)}</span>
                                    <span className="mt-auto flex items-end justify-between gap-2 pt-2">
                                        <span className="font-mono text-lg font-bold tabular-nums text-emerald-400">₱{product.selling_price}</span>
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" className="h-6 w-6 shrink-0 text-slate-400">
                                            <circle cx="12" cy="12" r="9" />
                                            <path d="M12 8v8M8 12h8" />
                                        </svg>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                {!searching && shown !== null && total > shown.length && (
                    <p className="mt-3 text-center text-xs text-slate-400">Showing the first {shown.length} of {total}. Search for the rest.</p>
                )}
            </div>
        </section>
    );
}

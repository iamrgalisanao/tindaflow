import { useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import MealPicker from './MealPicker';
import { fromPriceText, mealsForView, viewCount } from './mealMenu';
import { buildMenuTabs, groupFilter } from './menuGroups';
import { NO_STOCK_RULES, tileStock } from './stock';

const PAGE_SIZE = 100;

/**
 * Where the cashier finds what to sell: the scan/search box, the menu-group tabs and sub-tabs (or, in a store with no active
 * menu groups, the category tabs exactly as before), and a grid of tap-to-add product tiles.
 * The scan box keeps focus and behaves exactly as before (a scanned barcode adds the product; anything else is a name or
 * SKU search). A search replaces the grid with its results until it is cleared.
 *
 * `stock` (ADR-017 slice A) is the till's read of what is left of each tracked product. A tile shows "N in stock", an amber "Only N left"
 * at or below the low-stock threshold, or "No stock"; when the store blocks sales without stock, a tile with none is greyed and not tappable.
 * It is advisory (up to a minute old); checkout decides.
 */
export default function CatalogPanel({ search, onSearchChange, onSearch, onClearSearch, searchRef, scanNotice, results, onAdd, onAddMeal, stock = NO_STOCK_RULES }) {
    const [categories, setCategories] = useState([]);
    const [categoryId, setCategoryId] = useState(''); // '' = all
    const [menuTabs, setMenuTabs] = useState(null); // null = loading; [] = this store has no active menu groups
    const [groupId, setGroupId] = useState(''); // '' = all
    const [subGroupId, setSubGroupId] = useState(''); // '' = the whole top group
    const [tiles, setTiles] = useState(null); // null = loading
    const [loadFailed, setLoadFailed] = useState(false);
    const [total, setTotal] = useState(0);
    const [menu, setMenu] = useState({ meals: [], dish_categories: [] }); // GET /api/v1/menu; empty when it cannot be read
    const [pickingMeal, setPickingMeal] = useState(null);

    useEffect(() => {
        let cancelled = false;
        // A failed read leaves the till selling products without meals rather than blocking it.
        apiFetch('/api/v1/menu').then((response) => {
            if (!cancelled && response.ok && Array.isArray(response.body?.meals)) {
                setMenu({ meals: response.body.meals, dish_categories: response.body.dish_categories ?? [] });
            }
        });
        return () => {
            cancelled = true;
        };
    }, []);

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
        apiFetch(`/api/v1/menu-groups?active=true&per_page=${PAGE_SIZE}`).then((response) => {
            if (!cancelled) {
                // A failed read leaves the till on its category tabs rather than without navigation.
                setMenuTabs(response.ok ? buildMenuTabs(response.body.data) : []);
            }
        });
        return () => {
            cancelled = true;
        };
    }, []);

    const groupMode = menuTabs !== null && menuTabs.length > 0;
    const selectedTop = groupMode ? menuTabs.find((tab) => tab.id === groupId) : undefined;

    useEffect(() => {
        if (menuTabs === null) {
            return undefined;
        }
        let cancelled = false;
        setTiles(null);
        setLoadFailed(false);
        const filter = groupMode ? groupFilter(groupId, subGroupId) : categoryId === '' ? '' : `&category_id=${encodeURIComponent(categoryId)}`;
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
    }, [menuTabs, groupMode, categoryId, groupId, subGroupId]);

    const categoryName = (id) => categories.find((category) => category.id === id)?.name ?? '';

    const searching = results !== null;
    const shown = searching ? results : tiles;
    const mealTiles = searching || menuTabs === null ? [] : mealsForView(menu.meals, { groupMode, menuTabs: menuTabs ?? [], groupId, subGroupId, categoryId });

    const selectedCount = shown === null ? null : viewCount(total, mealTiles);

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
                    className={`rounded px-3 py-2 text-sm ${scanNotice.kind === 'added' ? 'bg-emerald-500/10 text-emerald-300' : 'bg-red-500/10 text-red-300'}`}
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
                <>
                    {groupMode && (
                        <div role="tablist" aria-label="Menu groups" className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
                            {[{ id: '', name: 'All', children: [] }, ...menuTabs].map((tab) => (
                                <TabButton
                                    key={tab.id || 'all'}
                                    selected={groupId === tab.id}
                                    count={groupId === tab.id && subGroupId === '' ? selectedCount : null}
                                    onClick={() => {
                                        setGroupId(tab.id);
                                        setSubGroupId('');
                                    }}
                                >
                                    {tab.name}
                                </TabButton>
                            ))}
                        </div>
                    )}
                    {groupMode && selectedTop && selectedTop.children.length > 0 && (
                        <div role="tablist" aria-label={`${selectedTop.name} sub-groups`} className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
                            {[{ id: '', name: `All ${selectedTop.name}` }, ...selectedTop.children].map((tab) => (
                                <TabButton key={tab.id || 'all'} small selected={subGroupId === tab.id} count={subGroupId === tab.id ? selectedCount : null} onClick={() => setSubGroupId(tab.id)}>
                                    {tab.name}
                                </TabButton>
                            ))}
                        </div>
                    )}
                    {menuTabs !== null && !groupMode && (
                        <div role="tablist" aria-label="Categories" className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
                            {[{ id: '', name: 'All' }, ...categories].map((category) => (
                                <TabButton key={category.id || 'all'} selected={categoryId === category.id} count={categoryId === category.id ? selectedCount : null} onClick={() => setCategoryId(category.id)}>
                                    {category.name}
                                </TabButton>
                            ))}
                        </div>
                    )}
                </>
            )}

            {pickingMeal && (
                <div className="min-h-0 flex-1 overflow-y-auto">
                    <MealPicker
                        key={pickingMeal.id}
                        meal={pickingMeal}
                        categories={menu.dish_categories}
                        onAdd={(line) => {
                            onAddMeal(line);
                            setPickingMeal(null);
                        }}
                        onCancel={() => setPickingMeal(null)}
                    />
                </div>
            )}

            <div className={`min-h-0 flex-1 overflow-y-auto ${pickingMeal ? 'hidden' : ''}`}>
                {mealTiles.length > 0 && (
                    <ul aria-label="Meals" className="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                        {mealTiles.map((meal) => (
                            <li key={meal.id}>
                                <button
                                    type="button"
                                    onClick={() => setPickingMeal(meal)}
                                    className="flex min-h-28 w-full flex-col rounded-lg border border-emerald-500/40 border-b-2 bg-emerald-500/5 p-3 text-left hover:bg-emerald-500/10 active:bg-emerald-500/15"
                                >
                                    <span className="font-mono text-[11px] uppercase tracking-wider text-emerald-400">Meal · choose options</span>
                                    <span className="mt-1 text-sm font-medium text-slate-100">{meal.name}</span>
                                    {meal.menu_group_path && <span className="mt-0.5 text-xs text-slate-400">{meal.menu_group_path}</span>}
                                    <span className="mt-auto pt-2 font-mono text-lg font-bold tabular-nums text-emerald-400">{fromPriceText(meal)}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                {shown === null && <p className="py-8 text-center text-sm text-slate-400">Loading products…</p>}
                {loadFailed && !searching && (
                    <p role="alert" className="rounded bg-red-500/10 px-3 py-2 text-sm text-red-300">
                        The products could not be loaded. You can still scan a barcode or search.
                    </p>
                )}
                {shown !== null && shown.length === 0 && mealTiles.length === 0 && !loadFailed && (
                    <p className="py-8 text-center text-sm text-slate-400">{searching ? 'No products match.' : groupMode ? 'No products in this group.' : 'No products in this category.'}</p>
                )}
                {shown !== null && shown.length > 0 && (
                    <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                        {shown.map((product) => {
                            const onHand = tileStock(stock, product.id);
                            return (
                            <li key={product.id}>
                                <button
                                    type="button"
                                    onClick={() => onAdd(product)}
                                    disabled={onHand?.blocked === true}
                                    aria-label={onHand ? `${product.name}, ${onHand.text}` : undefined}
                                    className={`flex min-h-28 w-full flex-col rounded-lg border border-slate-800 border-b-2 border-b-slate-950 p-3 text-left ${
                                        onHand?.blocked
                                            ? 'cursor-not-allowed bg-slate-900/60 opacity-60'
                                            : 'bg-slate-950/60 hover:border-emerald-500/60 hover:bg-emerald-500/5 active:bg-emerald-500/10'
                                    }`}
                                >
                                    <span className="truncate font-mono text-[11px] uppercase tracking-wider text-slate-400">{product.sku}</span>
                                    <span className="mt-1 line-clamp-2 text-sm font-medium text-slate-100">{product.name}</span>
                                    <span className="mt-0.5 truncate text-xs text-slate-400">{categoryName(product.category_id)}</span>
                                    {onHand && (
                                        <span
                                            data-stock={onHand.tone}
                                            className={`mt-1 w-fit rounded px-1.5 py-0.5 font-mono text-[11px] font-bold ${
                                                onHand.tone === 'out' ? 'bg-red-500/15 text-red-300' : onHand.tone === 'low' ? 'bg-amber-500/15 text-amber-300' : 'text-slate-500'
                                            }`}
                                        >
                                            {onHand.text}
                                        </span>
                                    )}
                                    <span className="mt-auto flex items-end justify-between gap-2 pt-2">
                                        <span className="font-mono text-lg font-bold tabular-nums text-emerald-400">₱{product.selling_price}</span>
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" className="h-6 w-6 shrink-0 text-slate-400">
                                            <circle cx="12" cy="12" r="9" />
                                            <path d="M12 8v8M8 12h8" />
                                        </svg>
                                    </span>
                                </button>
                            </li>
                            );
                        })}
                    </ul>
                )}
                {!searching && shown !== null && total > shown.length && (
                    <p className="mt-3 text-center text-xs text-slate-400">Showing the first {shown.length} of {total}. Search for the rest.</p>
                )}
            </div>
        </section>
    );
}

/** One tab of a tab strip; `small` is the sub-tab size, `count` the number of products shown when it is the selected tab. */
function TabButton({ selected, count, small = false, onClick, children }) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={onClick}
            className={`flex shrink-0 items-center gap-2 rounded border font-mono font-bold uppercase tracking-widest ${
                small ? 'min-h-10 px-3 text-[10px]' : 'min-h-11 px-4 text-[11px]'
            } ${selected ? 'border-emerald-500 bg-emerald-500 text-slate-950' : 'border-slate-800 bg-slate-950/60 text-slate-400 hover:bg-slate-800'}`}
        >
            {children}
            {count !== null && <span className="rounded bg-slate-950/25 px-1.5 py-0.5 text-[10px]">{count}</span>}
        </button>
    );
}

import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ActiveBadge, ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fetchAll } from '../catalog/catalogApi';
import { moveId } from '../catalog/menuGroupOptions';
import AlaCartePanel from './AlaCartePanel';
import { dishesByCategory } from './menuConfig';
import { Loading, MoveButtons, NameForm, linkButton, primaryButton, inputClass } from './menuParts';
import { useMenuAction } from './useMenuAction';

/**
 * /admin/menu/dishes -- the dishes the meal picker offers, grouped by dish category (the price category: Beef, Chicken/Pork/Fish,
 * Vegetable ...). Add, rename, move to another category, reorder, hide and show. Nothing is deleted (ADR-016, MENU-005), and a
 * rename never changes a past sale: sales keep their own copy of the name.
 *
 * ADR-017 slice B: each dish can count DAILY stock (portions entered each morning in Today's stock), and "Create ala carte items" makes a sellable
 * product for every daily dish, drawing from the same pool as its meals.
 */
export default function DishesPage() {
    const { setUser } = useAuth();
    const [categories, setCategories] = useState(null);
    const [dishes, setDishes] = useState([]);
    const [failure, setFailure] = useState(null);
    const [toast, setToast] = useState(null);
    // { category: id } renaming | { dish: id } renaming | { newDishIn: categoryId } | { newCategory: true }
    const [editing, setEditing] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(async () => {
        const current = ++seq.current;
        const [categoryResult, dishResult] = await Promise.all([fetchAll('/api/v1/dish-categories'), fetchAll('/api/v1/dishes')]);
        if (current !== seq.current) {
            return;
        }
        if (categoryResult.ok && dishResult.ok) {
            setCategories(categoryResult.rows);
            setDishes(dishResult.rows);
            setFailure(null);
        } else {
            setFailure(categoryResult.ok ? dishResult : categoryResult);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const { run, busy, fieldError, clearFieldError } = useMenuAction(load, setFailure, setToast);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const finish = { onSuccess: () => setEditing(null) };
    const startEditing = (state) => {
        clearFieldError();
        setEditing(state);
    };

    const addCategory = (name) => run('/api/v1/dish-categories', { method: 'POST', body: { name } }, { ...finish, done: 'Dish category added' });
    const renameCategory = (category) => (name) => run(`/api/v1/dish-categories/${category.id}`, { method: 'PATCH', body: { name } }, { ...finish, done: 'Dish category renamed' });
    const toggleCategory = (category) =>
        run(`/api/v1/dish-categories/${category.id}/${category.active ? 'deactivate' : 'activate'}`, { method: 'POST' }, { done: category.active ? 'Category hidden' : 'Category shown' });
    const moveCategory = (rows, category, direction) => {
        const ids = rows.map((row) => row.id);
        const next = moveId(ids, category.id, direction);
        if (next !== ids) {
            run('/api/v1/dish-categories/reorder', { method: 'POST', body: { ids: next } });
        }
    };

    const addDish = (categoryId) => (name) => run('/api/v1/dishes', { method: 'POST', body: { name, dish_category_id: categoryId } }, { ...finish, done: 'Dish added' });
    const renameDish = (dish) => (name) => run(`/api/v1/dishes/${dish.id}`, { method: 'PATCH', body: { name } }, { ...finish, done: 'Dish renamed' });
    const toggleDish = (dish) => run(`/api/v1/dishes/${dish.id}/${dish.active ? 'deactivate' : 'activate'}`, { method: 'POST' }, { done: dish.active ? 'Dish hidden from the picker' : 'Dish shown in the picker' });
    const toggleDailyStock = (dish) =>
        run(`/api/v1/dishes/${dish.id}`, { method: 'PATCH', body: { stock_mode: dish.stock_mode === 'DAILY' ? 'NONE' : 'DAILY' } }, { done: dish.stock_mode === 'DAILY' ? 'Daily stock switched off' : 'Daily stock switched on' });
    const moveDishToCategory = (dish, categoryId) => run(`/api/v1/dishes/${dish.id}`, { method: 'PATCH', body: { dish_category_id: categoryId } }, { done: 'Dish moved' });
    const moveDish = (rows, categoryId, dish, direction) => {
        const ids = rows.map((row) => row.id);
        const next = moveId(ids, dish.id, direction);
        if (next !== ids) {
            run('/api/v1/dishes/reorder', { method: 'POST', body: { dish_category_id: categoryId, ids: next } });
        }
    };

    const grouped = categories ? dishesByCategory(categories, dishes) : [];

    return (
        <AdminLayout title="Meal menu / Dishes" requiredCapability="CATALOG_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Dishes</h2>
            <p className="mb-4 max-w-2xl text-sm text-slate-400">
                The dishes cashiers choose from when ringing a meal. A meal is priced by the dish category (for example Beef), not by the dish, so moving a dish to another category changes
                what it costs from the next sale. Renaming or hiding a dish never changes a past sale. Dishes are never deleted.
            </p>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The dishes could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}
            {categories === null && !failure && <Loading label="Loading dishes" />}

            {categories !== null && (
                <>
                    {editing?.alaCarte && <AlaCartePanel categories={categories} dishes={dishes} onDone={() => load()} onCancel={() => setEditing(null)} />}

                    <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3">
                        <button type="button" onClick={() => startEditing({ alaCarte: true })} className="min-h-11 rounded border border-emerald-600 px-4 text-sm font-semibold text-emerald-300 hover:bg-emerald-500/10 lg:min-h-0 lg:py-1.5">
                            Create ala carte items for dishes
                        </button>
                        {editing?.newCategory ? (
                            <NameForm label="New dish category" submitLabel="Add category" busy={busy} error={fieldError} onSubmit={addCategory} onCancel={() => setEditing(null)} />
                        ) : (
                            <button type="button" onClick={() => startEditing({ newCategory: true })} className={primaryButton}>
                                Add dish category
                            </button>
                        )}
                    </div>

                    {grouped.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-300">No dish categories yet. Add one (for example Beef), then add its dishes.</div>
                    ) : (
                        <div className="space-y-4">
                            {grouped.map((category, categoryIndex) => (
                                <section key={category.id} aria-label={category.name} className="overflow-hidden rounded-lg border border-slate-800">
                                    <header className="flex flex-wrap items-center gap-2 bg-slate-900 px-3 py-2">
                                        <div className="min-w-0 flex-1">
                                            {editing?.category === category.id ? (
                                                <NameForm initial={category.name} label="Category name" submitLabel="Save" busy={busy} error={fieldError} onSubmit={renameCategory(category)} onCancel={() => setEditing(null)} />
                                            ) : (
                                                <h3 className={`font-semibold ${category.active ? 'text-slate-100' : 'text-slate-500'}`}>{category.name}</h3>
                                            )}
                                        </div>
                                        {editing?.category !== category.id && (
                                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                                <ActiveBadge active={category.active} />
                                                <MoveButtons name={category.name} index={categoryIndex} count={grouped.length} busy={busy} onMove={(direction) => moveCategory(grouped, category, direction)} />
                                                <button type="button" onClick={() => startEditing({ category: category.id })} className={linkButton}>
                                                    Rename
                                                </button>
                                                <button type="button" disabled={busy} onClick={() => toggleCategory(category)} className={`min-h-11 hover:underline lg:min-h-0 ${category.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}>
                                                    {category.active ? 'Hide' : 'Show'}
                                                </button>
                                                <button type="button" onClick={() => startEditing({ newDishIn: category.id })} className={linkButton}>
                                                    Add dish
                                                </button>
                                            </div>
                                        )}
                                    </header>
                                    <ul aria-label={`${category.name} dishes`}>
                                        {category.dishes.map((dish, index) => (
                                            <li key={dish.id} className="flex flex-wrap items-center gap-2 border-t border-slate-800/70 px-3 py-2 pl-6">
                                                <div className="min-w-0 flex-1">
                                                    {editing?.dish === dish.id ? (
                                                        <NameForm initial={dish.name} label="Dish name" submitLabel="Save" busy={busy} error={fieldError} onSubmit={renameDish(dish)} onCancel={() => setEditing(null)} />
                                                    ) : (
                                                        <span className={dish.active ? 'text-slate-100' : 'text-slate-500'}>
                                                            {dish.name}
                                                            {dish.stock_mode === 'DAILY' && <span className="ml-2 rounded bg-emerald-500/15 px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-emerald-300">daily stock</span>}
                                                            {(dish.ala_carte_products ?? []).map((item) => (
                                                                <span key={item.id} className="ml-2 font-mono text-[11px] text-slate-500">
                                                                    {item.name} ₱{item.selling_price}
                                                                </span>
                                                            ))}
                                                        </span>
                                                    )}
                                                </div>
                                                {editing?.dish !== dish.id && (
                                                    <div className="flex flex-wrap items-center gap-3 text-xs">
                                                        <ActiveBadge active={dish.active} />
                                                        <MoveButtons name={dish.name} index={index} count={category.dishes.length} busy={busy} onMove={(direction) => moveDish(category.dishes, category.id, dish, direction)} />
                                                        <button type="button" onClick={() => startEditing({ dish: dish.id })} className={linkButton}>
                                                            Rename
                                                        </button>
                                                        <label className="sr-only" htmlFor={`move_${dish.id}`}>
                                                            Category of {dish.name}
                                                        </label>
                                                        <select id={`move_${dish.id}`} value={category.id} disabled={busy} onChange={(event) => moveDishToCategory(dish, event.target.value)} className={`${inputClass(false)} w-auto max-w-[11rem] py-1 text-xs`}>
                                                            {categories.map((option) => (
                                                                <option key={option.id} value={option.id}>
                                                                    {option.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <button type="button" disabled={busy} onClick={() => toggleDailyStock(dish)} aria-label={`Daily stock for ${dish.name}: ${dish.stock_mode === 'DAILY' ? 'on' : 'off'}`} className={linkButton}>
                                                            {dish.stock_mode === 'DAILY' ? 'Daily stock: on' : 'Daily stock: off'}
                                                        </button>
                                                        <button type="button" disabled={busy} onClick={() => toggleDish(dish)} className={`min-h-11 hover:underline lg:min-h-0 ${dish.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}>
                                                            {dish.active ? 'Hide' : 'Show'}
                                                        </button>
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                        {category.dishes.length === 0 && editing?.newDishIn !== category.id && <li className="border-t border-slate-800/70 px-3 py-2 pl-6 text-sm text-slate-500">No dishes yet.</li>}
                                        {editing?.newDishIn === category.id && (
                                            <li className="border-t border-slate-800/70 px-3 py-2 pl-6">
                                                <NameForm label={`New ${category.name} dish`} submitLabel="Add dish" busy={busy} error={fieldError} onSubmit={addDish(category.id)} onCancel={() => setEditing(null)} />
                                            </li>
                                        )}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    )}
                </>
            )}
        </AdminLayout>
    );
}

import { useEffect, useMemo, useState } from 'react';
import { failureMessage, fieldErrors, normalizeMoney, request } from '../catalog/catalogApi';
import { inputClass, primaryButton } from './menuParts';

/**
 * "Create ala carte items for dishes" (ADR-017 slice B, client decision 2): every dish can be sold alone, so each daily dish gets an ordinary product
 * that draws from the same daily pool as the dish's meals (2 portions per order by default). The manager chooses the dishes and sets the prices, one per
 * dish category or one per dish; the system assumes none. The names default to "<Dish> (ala carte)", the SKU is made up, and the items go in the menu group
 * the manager picks. Running it again creates nothing twice.
 */
export default function AlaCartePanel({ categories, dishes, onDone, onCancel }) {
    const eligible = useMemo(() => dishes.filter((dish) => dish.stock_mode === 'DAILY' && dish.active && (dish.ala_carte_products ?? []).length === 0), [dishes]);
    const [groups, setGroups] = useState([]);
    const [groupId, setGroupId] = useState('');
    const [picked, setPicked] = useState(() => new Set(eligible.map((dish) => dish.id)));
    const [categoryPrices, setCategoryPrices] = useState({});
    const [dishPrices, setDishPrices] = useState({});
    const [portion, setPortion] = useState('2');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => {
        let live = true;
        request('/api/v1/menu-groups?active=true&per_page=100').then((response) => {
            if (live && response.ok) {
                setGroups(response.body.data);
            }
        });

        return () => {
            live = false;
        };
    }, []);

    const priceFor = (dish) => normalizeMoney(dishPrices[dish.id] ?? '') ?? normalizeMoney(categoryPrices[dish.dish_category_id] ?? '');
    const chosen = eligible.filter((dish) => picked.has(dish.id));
    const missing = chosen.filter((dish) => priceFor(dish) === null);
    const ready = chosen.length > 0 && missing.length === 0 && /^\d{1,3}(\.\d{1,3})?$/.test(portion.trim()) && Number(portion) > 0 && !busy;

    async function submit(event) {
        event.preventDefault();
        if (!ready) {
            return;
        }
        setBusy(true);
        setError(null);
        const response = await request('/api/v1/dishes/ala-carte', {
            method: 'POST',
            body: {
                ...(groupId ? { menu_group_id: groupId } : {}),
                stock_portion: portion.trim(),
                items: chosen.map((dish) => ({ dish_id: dish.id, price: priceFor(dish) })),
            },
        });
        setBusy(false);
        if (response.ok) {
            setResult(response.body);
            onDone(response.body);

            return;
        }
        setError(Object.values(fieldErrors(response))[0] ?? failureMessage(response, 'The items could not be created.'));
    }

    if (result) {
        return (
            <div role="status" className="mb-4 rounded-lg border border-emerald-800 bg-emerald-950/30 p-4 text-sm text-slate-200">
                <p className="font-semibold">
                    {result.created.length} ala carte item{result.created.length === 1 ? '' : 's'} created.
                    {result.skipped.length > 0 && ` ${result.skipped.length} skipped (already has one, or does not count daily stock).`}
                </p>
                <ul className="mt-2 text-xs text-slate-400">
                    {result.created.map((item) => (
                        <li key={item.product_id}>
                            {item.name} <span className="font-mono">{item.sku}</span> ₱{item.selling_price}
                        </li>
                    ))}
                </ul>
                <button type="button" onClick={onCancel} className="mt-3 min-h-11 rounded border border-slate-600 px-4 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                    Close
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} noValidate aria-label="Create ala carte items" className="mb-4 space-y-4 rounded-lg border border-slate-700 bg-slate-900 p-4">
            <div>
                <h3 className="text-sm font-semibold text-slate-100">Create ala carte items for dishes</h3>
                <p className="mt-1 max-w-2xl text-xs text-slate-400">
                    Each chosen dish becomes a product the cashier can sell alone. It draws {portion || '2'} portions of the dish from the same daily stock as the dish&apos;s meals. You set the
                    prices; nothing is assumed. Dishes that already have an ala carte item are not listed.
                </p>
            </div>

            {eligible.length === 0 ? (
                <p className="text-sm text-slate-400">Every dish that counts daily stock already has an ala carte item. Switch &ldquo;Daily stock&rdquo; on for a dish first.</p>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label htmlFor="ala_group" className="mb-1 block text-xs text-slate-400">Menu group (optional)</label>
                            <select id="ala_group" value={groupId} onChange={(event) => setGroupId(event.target.value)} className={inputClass(false)}>
                                <option value="">No menu group</option>
                                {groups.map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="ala_portion" className="mb-1 block text-xs text-slate-400">Portions per order</label>
                            <input id="ala_portion" inputMode="decimal" value={portion} onChange={(event) => setPortion(event.target.value)} className={inputClass(false)} />
                        </div>
                    </div>

                    <fieldset>
                        <legend className="mb-1 text-xs text-slate-400">Price per dish category (a price on a dish below overrides it)</legend>
                        <div className="flex flex-wrap gap-3">
                            {categories.filter((category) => eligible.some((dish) => dish.dish_category_id === category.id)).map((category) => (
                                <div key={category.id}>
                                    <label htmlFor={`ala_cat_${category.id}`} className="mb-1 block text-xs text-slate-300">{category.name}</label>
                                    <input
                                        id={`ala_cat_${category.id}`}
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        value={categoryPrices[category.id] ?? ''}
                                        onChange={(event) => setCategoryPrices((prior) => ({ ...prior, [category.id]: event.target.value }))}
                                        className={`${inputClass(false)} w-28 text-right font-mono`}
                                    />
                                </div>
                            ))}
                        </div>
                    </fieldset>

                    <ul aria-label="Dishes to create" className="divide-y divide-slate-800 rounded border border-slate-800">
                        {eligible.map((dish) => (
                            <li key={dish.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                <label className="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-100">
                                    <input
                                        type="checkbox"
                                        checked={picked.has(dish.id)}
                                        onChange={(event) =>
                                            setPicked((prior) => {
                                                const next = new Set(prior);
                                                event.target.checked ? next.add(dish.id) : next.delete(dish.id);

                                                return next;
                                            })
                                        }
                                        className="h-5 w-5 rounded border-slate-600 bg-slate-950 text-emerald-500"
                                    />
                                    <span className="truncate">{dish.name} (ala carte)</span>
                                </label>
                                <input
                                    aria-label={`Price for ${dish.name}`}
                                    inputMode="decimal"
                                    placeholder={categoryPrices[dish.dish_category_id] || '0.00'}
                                    value={dishPrices[dish.id] ?? ''}
                                    onChange={(event) => setDishPrices((prior) => ({ ...prior, [dish.id]: event.target.value }))}
                                    className={`${inputClass(false)} w-28 text-right font-mono`}
                                />
                            </li>
                        ))}
                    </ul>
                    {missing.length > 0 && chosen.length > 0 && <p role="status" className="text-xs text-amber-300">Set a price for {missing.map((dish) => dish.name).join(', ')}.</p>}
                </>
            )}

            {error && (
                <p role="alert" className="rounded border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                    {error}
                </p>
            )}

            <div className="flex gap-3">
                <button type="submit" disabled={!ready} className={`${primaryButton} disabled:cursor-not-allowed disabled:opacity-40`}>
                    {busy ? 'Creating…' : `Create ${chosen.length} item${chosen.length === 1 ? '' : 's'}`}
                </button>
                <button type="button" onClick={onCancel} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                    Cancel
                </button>
            </div>
        </form>
    );
}

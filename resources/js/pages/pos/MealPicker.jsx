import { useState } from 'react';

/**
 * The two-step picker for a meal menu: the protein first (its price is on the button), then the dish. The add button
 * stays inactive until both are chosen and then shows the price being added.
 */
export default function MealPicker({ menu, productsBySku, onAdd, onCancel }) {
    const [protein, setProtein] = useState(null);
    const [dish, setDish] = useState(null);

    const product = protein ? productsBySku[protein.sku] : null;
    const ready = product && dish;

    return (
        <div className="flex flex-col gap-4" role="dialog" aria-label={`Choose ${menu.name}`}>
            <div className="flex items-center justify-between">
                <button type="button" onClick={onCancel} className="min-h-11 px-2 text-sm text-slate-400 underline">
                    Back
                </button>
                <h3 className="text-sm font-semibold text-slate-100">{menu.name}</h3>
                <span className="w-12" />
            </div>

            <div>
                <p className="mb-2 font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">Step 1 · Protein</p>
                <ul className="flex flex-col gap-2">
                    {menu.proteins.map((option) => {
                        const optionProduct = productsBySku[option.sku];
                        const selected = protein?.sku === option.sku;
                        return (
                            <li key={option.sku}>
                                <button
                                    type="button"
                                    disabled={!optionProduct}
                                    aria-pressed={selected}
                                    onClick={() => {
                                        setProtein(option);
                                        setDish(null);
                                    }}
                                    className={`flex min-h-14 w-full items-center justify-between rounded-lg border px-4 text-left text-sm disabled:opacity-40 ${
                                        selected ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-slate-800 bg-slate-950/60 text-slate-100 hover:border-emerald-500/60'
                                    }`}
                                >
                                    <span>{option.label}</span>
                                    <span className="font-mono font-bold tabular-nums">{optionProduct ? `₱${optionProduct.selling_price}` : 'Not in catalog'}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </div>

            {protein && (
                <div>
                    <p className="mb-2 font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">Step 2 · Dish</p>
                    <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {protein.dishes.map((name) => (
                            <li key={name}>
                                <button
                                    type="button"
                                    aria-pressed={dish === name}
                                    onClick={() => setDish(name)}
                                    className={`min-h-14 w-full rounded-lg border px-3 text-sm ${
                                        dish === name ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-slate-800 bg-slate-950/60 text-slate-100 hover:border-emerald-500/60'
                                    }`}
                                >
                                    {name}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <button
                type="button"
                disabled={!ready}
                onClick={() => onAdd(product, `${dish} · ${protein.label}`)}
                className="min-h-14 rounded-lg bg-emerald-500 px-4 font-semibold text-slate-950 hover:bg-emerald-400 disabled:bg-slate-800 disabled:text-slate-500"
            >
                {ready ? `Add to order ₱${product.selling_price}` : 'Choose a protein and a dish'}
            </button>
        </div>
    );
}

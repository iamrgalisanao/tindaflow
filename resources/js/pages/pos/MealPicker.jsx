import { useState } from 'react';
import { addonGroups, buildMealLine, categoryChoices, categoryOfOption, choiceGroups, choicesComplete, dishesIn, isComplete, pricingGroup, previewCents, togglePick } from './mealMenu';
import { pesos } from './posMoney';
import { NO_STOCK_RULES, dishAvailability } from './stock';

const tile = (selected) =>
    `min-h-14 w-full rounded-lg border px-3 text-left text-sm ${
        selected ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-slate-800 bg-slate-950/60 text-slate-100 hover:border-emerald-500/60'
    }`;

const heading = 'mb-2 font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400';

/**
 * The picker for one meal of the menu (GET /api/v1/menu). It follows the meal's own groups in step order: for the group that
 * prices the meal, first the dish category (its price is on the button), then the dishes of that category; any other choice
 * group (the Combo's side) lists its dishes. When the choices are complete, an add-on group flagged `popup_after_choice` (the
 * rice) opens as a pop-up the cashier can skip if it allows none. The Add button stays inactive until every group has its
 * required picks and then shows what is being added. Ids only go back up (`onAdd`): the server prices the sale itself.
 *
 * `stock` and `cart` (ADR-017 slice B): a dish that counts daily stock shows the portions left ("3 left") or "Sold out" after what the cart already
 * takes from it; when the store blocks sales without stock a sold-out dish cannot be picked. Advisory; checkout decides.
 */
export default function MealPicker({ meal, categories, onAdd, onCancel, stock = NO_STOCK_RULES, cart = [] }) {
    const [picks, setPicks] = useState({});
    const [categoryId, setCategoryId] = useState(null);
    const [popupGroupId, setPopupGroupId] = useState(null);
    const [handled, setHandled] = useState([]); // add-on groups the pop-up has already been shown for

    const groups = choiceGroups(meal);
    const priced = pricingGroup(meal);
    const popupGroups = addonGroups(meal).filter((group) => group.popup_after_choice);
    const inlineAddons = addonGroups(meal).filter((group) => !group.popup_after_choice);
    const popupGroup = popupGroups.find((group) => group.id === popupGroupId);
    const ready = isComplete(meal, picks);
    const { total } = previewCents(meal, picks);

    /** After a choice, show the next unhandled pop-up group once every choice group is satisfied. */
    function afterChoice(next) {
        if (!choicesComplete(meal, next)) {
            return;
        }
        const waiting = popupGroups.find((group) => !handled.includes(group.id));
        if (waiting) {
            setHandled((prior) => [...prior, waiting.id]);
            setPopupGroupId(waiting.id);
        }
    }

    function choose(group, option) {
        const next = togglePick(picks, group, option.id);
        setPicks(next);
        afterChoice(next);
    }

    function chooseCategory(id) {
        setCategoryId(id);
        // A new category invalidates the dish picked from the old one.
        if (priced) {
            const kept = (picks[priced.id] ?? []).filter((optionId) => categoryOfOption(priced.options.find((option) => option.id === optionId)) === id);
            setPicks({ ...picks, [priced.id]: kept });
        }
    }

    function chooseAddon(group, option) {
        const next = togglePick(picks, group, option.id);
        setPicks(next);
        if (group.max_select <= 1) {
            setPopupGroupId(null);
        }
    }

    const missing = meal.option_groups.filter((group) => (picks[group.id] ?? []).length < group.min_select);

    return (
        <div className="flex flex-col gap-4" role="dialog" aria-label={`Choose ${meal.name}`}>
            <div className="flex items-center justify-between">
                <button type="button" onClick={onCancel} className="min-h-11 px-2 text-sm text-slate-400 underline">
                    Back
                </button>
                <h3 className="text-sm font-semibold text-slate-100">{meal.name}</h3>
                <span className="w-12" />
            </div>

            {groups.map((group) => {
                const isPricing = priced?.id === group.id;
                const chosenIds = picks[group.id] ?? [];
                return (
                    <div key={group.id}>
                        {isPricing && (
                            <>
                                <p className={heading}>
                                    {group.name} · category
                                </p>
                                <ul className="mb-4 flex flex-col gap-2">
                                    {categoryChoices(meal, categories).map((category) => (
                                        <li key={category.id}>
                                            <button
                                                type="button"
                                                aria-pressed={categoryId === category.id}
                                                onClick={() => chooseCategory(category.id)}
                                                className={`${tile(categoryId === category.id)} flex items-center justify-between`}
                                            >
                                                <span>{category.name}</span>
                                                <span className="font-mono font-bold tabular-nums">{category.price ? `₱${category.price}` : ''}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {(!isPricing || categoryId !== null) && (
                            <>
                                <p className={heading}>{isPricing ? `${group.name} · dish` : group.name}</p>
                                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {dishesIn(group, isPricing ? categoryId : null).map((option) => {
                                        const available = option.stock_portion ? dishAvailability(stock, cart, option.dish.id, option.stock_portion) : null;
                                        const chosen = chosenIds.includes(option.id);
                                        return (
                                            <li key={option.id}>
                                                <button
                                                    type="button"
                                                    aria-pressed={chosen}
                                                    disabled={!chosen && available?.blocked === true}
                                                    onClick={() => choose(group, option)}
                                                    className={`${tile(chosen)} ${!chosen && available?.blocked ? 'cursor-not-allowed opacity-50' : ''}`}
                                                >
                                                    {option.label}
                                                    {available && (
                                                        <span data-stock={available.soldOut ? 'out' : 'ok'} className={`mt-0.5 block font-mono text-[11px] font-bold ${available.soldOut ? 'text-red-300' : 'text-slate-500'}`}>
                                                            {available.text}
                                                        </span>
                                                    )}
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </>
                        )}
                    </div>
                );
            })}

            {[...popupGroups, ...inlineAddons].map((group) => {
                const chosen = group.options.filter((option) => (picks[group.id] ?? []).includes(option.id));
                const reached = !group.popup_after_choice || handled.includes(group.id) || chosen.length > 0;
                if (!reached || (group.popup_after_choice && !choicesComplete(meal, picks))) {
                    return null;
                }
                return (
                    <div key={group.id} className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-950/60 px-3 py-2">
                        <span className="text-sm text-slate-200">
                            {group.name}: <span className="text-emerald-300">{chosen.length === 0 ? 'none' : chosen.map((option) => `${option.label} ₱${option.price}`).join(', ')}</span>
                        </span>
                        <button type="button" onClick={() => setPopupGroupId(group.id)} className="min-h-11 px-2 text-sm text-slate-400 underline">
                            Change
                        </button>
                    </div>
                );
            })}

            <button
                type="button"
                disabled={!ready}
                onClick={() => onAdd(buildMealLine(meal, picks))}
                className="min-h-14 rounded-lg bg-emerald-500 px-4 font-semibold text-slate-950 hover:bg-emerald-400 disabled:bg-slate-800 disabled:text-slate-500"
            >
                {ready ? `Add to order ₱${pesos(total)}` : missing.length > 0 ? `Choose ${missing.map((group) => group.name.toLowerCase()).join(' and ')}` : 'Choose the options'}
            </button>

            {popupGroup && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4" role="presentation">
                    <div role="dialog" aria-modal="true" aria-label={popupGroup.name} className="w-full max-w-sm rounded-lg border border-slate-700 bg-slate-900 p-4">
                        <h4 className="mb-3 text-sm font-semibold text-slate-100">{popupGroup.name}?</h4>
                        <ul className="flex flex-col gap-2">
                            {popupGroup.options.map((option) => {
                                const selected = (picks[popupGroup.id] ?? []).includes(option.id);
                                return (
                                    <li key={option.id}>
                                        <button
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() => chooseAddon(popupGroup, option)}
                                            className={`${tile(selected)} flex items-center justify-between`}
                                        >
                                            <span>{option.label}</span>
                                            <span className="font-mono font-bold tabular-nums">₱{option.price}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        <div className="mt-3 flex gap-2">
                            {popupGroup.min_select === 0 && (picks[popupGroup.id] ?? []).length === 0 && (
                                <button type="button" onClick={() => setPopupGroupId(null)} className="min-h-12 flex-1 rounded-lg border border-slate-700 text-sm text-slate-200 hover:bg-slate-800">
                                    Skip
                                </button>
                            )}
                            {popupGroup.max_select > 1 || (picks[popupGroup.id] ?? []).length > 0 ? (
                                <button type="button" onClick={() => setPopupGroupId(null)} className="min-h-12 flex-1 rounded-lg bg-emerald-500 text-sm font-semibold text-slate-950 hover:bg-emerald-400">
                                    Done
                                </button>
                            ) : null}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

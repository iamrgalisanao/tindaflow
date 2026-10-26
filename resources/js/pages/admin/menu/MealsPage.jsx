import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ActiveBadge, ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fetchAll } from '../catalog/catalogApi';
import { menuGroupOptions, moveId } from '../catalog/menuGroupOptions';
import MealGroupForm from './MealGroupForm';
import { dishChoicesFor, pickRule, unpricedNames } from './menuConfig';
import { FieldError, Loading, MoveButtons, NameForm, inputClass, linkButton, primaryButton, secondaryButton } from './menuParts';
import { useMenuAction } from './useMenuAction';

/** Adds one option to a group: a dish picked from the dishes not yet offered, or a labelled add-on. */
function OptionAdder({ group, choices, busy, error, onSubmit, onCancel }) {
    const [dishId, setDishId] = useState('');
    const [label, setLabel] = useState('');
    const addon = group.kind === 'ADDON';

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(addon ? { label: label.trim() } : { dish_id: dishId });
            }}
            className="flex flex-col gap-2 sm:flex-row sm:items-start"
            noValidate
        >
            <div className="min-w-0 flex-1">
                {addon ? (
                    <>
                        <label htmlFor={`add_${group.id}`} className="sr-only">
                            Add-on label
                        </label>
                        <input id={`add_${group.id}`} type="text" autoFocus value={label} placeholder="e.g. Whole rice" onChange={(event) => setLabel(event.target.value)} className={inputClass(error)} />
                    </>
                ) : (
                    <>
                        <label htmlFor={`add_${group.id}`} className="sr-only">
                            Dish to offer
                        </label>
                        <select id={`add_${group.id}`} autoFocus value={dishId} onChange={(event) => setDishId(event.target.value)} className={inputClass(error)}>
                            <option value="">Choose a dish…</option>
                            {choices.map((choice) => (
                                <option key={choice.id} value={choice.id}>
                                    {choice.label}
                                </option>
                            ))}
                        </select>
                    </>
                )}
                <FieldError message={error} />
            </div>
            <div className="flex gap-2">
                <button type="submit" disabled={busy || (addon ? label.trim() === '' : dishId === '')} className={primaryButton}>
                    {busy ? 'Saving…' : addon ? 'Add add-on' : 'Offer dish'}
                </button>
                <button type="button" onClick={onCancel} className={secondaryButton}>
                    Cancel
                </button>
            </div>
        </form>
    );
}

/**
 * /admin/menu/meals -- build a meal: its steps (option groups) and what each step offers. Exactly one step "prices the meal": the
 * dish chosen there decides the price by its dish category. Prices themselves are set on the Meal prices screen. Steps and options
 * are hidden, never deleted (ADR-016, MENU-005).
 */
export default function MealsPage() {
    const { setUser } = useAuth();
    const [meals, setMeals] = useState(null);
    const [dishes, setDishes] = useState([]);
    const [categories, setCategories] = useState([]);
    const [menuGroups, setMenuGroups] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [failure, setFailure] = useState(null);
    const [toast, setToast] = useState(null);
    // { newMeal } | { renameMeal } | { newGroup } | { editGroup: id } | { newOption: groupId } | { renameOption: id }
    const [editing, setEditing] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(async () => {
        const current = ++seq.current;
        const [mealResult, dishResult, categoryResult, groupResult] = await Promise.all([
            fetchAll('/api/v1/meals'),
            fetchAll('/api/v1/dishes'),
            fetchAll('/api/v1/dish-categories'),
            fetchAll('/api/v1/menu-groups'),
        ]);
        if (current !== seq.current) {
            return;
        }
        const failed = [mealResult, dishResult, categoryResult, groupResult].find((result) => !result.ok);
        if (failed) {
            setFailure(failed);
            return;
        }
        setMeals(mealResult.rows);
        setDishes(dishResult.rows);
        setCategories(categoryResult.rows);
        setMenuGroups(groupResult.rows);
        setSelectedId((id) => (mealResult.rows.some((meal) => meal.id === id) ? id : (mealResult.rows[0]?.id ?? null)));
        setFailure(null);
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

    const startEditing = (state) => {
        clearFieldError();
        setEditing(state);
    };
    const finish = (done) => ({ done, onSuccess: () => setEditing(null) });

    const meal = meals?.find((candidate) => candidate.id === selectedId) ?? null;
    const groupChoices = menuGroupOptions(menuGroups);
    const missing = meal ? unpricedNames(meal, categories) : [];

    const addMeal = (name) =>
        run('/api/v1/meals', { method: 'POST', body: { name } }, { done: 'Meal added', onSuccess: (created) => { setEditing(null); setSelectedId(created.id); } });
    const renameMeal = (name) => run(`/api/v1/meals/${meal.id}`, { method: 'PATCH', body: { name } }, finish('Meal renamed'));
    const placeMeal = (menuGroupId) => run(`/api/v1/meals/${meal.id}`, { method: 'PATCH', body: { menu_group_id: menuGroupId || null } }, { done: 'Meal placed on the till' });
    const toggleMeal = () => run(`/api/v1/meals/${meal.id}/${meal.active ? 'deactivate' : 'activate'}`, { method: 'POST' }, { done: meal.active ? 'Meal hidden from the till' : 'Meal shown on the till' });
    const moveMeal = (target, direction) => {
        const ids = meals.map((row) => row.id);
        const next = moveId(ids, target.id, direction);
        if (next !== ids) {
            run('/api/v1/meals/reorder', { method: 'POST', body: { ids: next } });
        }
    };

    const addGroup = (body) => run(`/api/v1/meals/${meal.id}/option-groups`, { method: 'POST', body }, finish('Step added'));
    const saveGroup = (group) => (body) => run(`/api/v1/meal-option-groups/${group.id}`, { method: 'PATCH', body }, finish('Step saved'));
    const toggleGroup = (group) => run(`/api/v1/meal-option-groups/${group.id}/${group.active ? 'deactivate' : 'activate'}`, { method: 'POST' }, { done: group.active ? 'Step hidden' : 'Step shown' });
    const makePricing = (group) => run(`/api/v1/meal-option-groups/${group.id}`, { method: 'PATCH', body: { prices_the_meal: true } }, { done: `"${group.name}" now prices the meal` });
    const moveGroup = (group, direction) => {
        const ids = meal.option_groups.map((row) => row.id);
        const next = moveId(ids, group.id, direction);
        if (next !== ids) {
            run(`/api/v1/meals/${meal.id}/option-groups/reorder`, { method: 'POST', body: { ids: next } });
        }
    };

    const addOption = (group) => (body) => run(`/api/v1/meal-option-groups/${group.id}/options`, { method: 'POST', body }, finish('Option added'));
    const renameOption = (option) => (label) => run(`/api/v1/meal-options/${option.id}`, { method: 'PATCH', body: { label } }, finish('Add-on renamed'));
    const savePortion = (option) => (text) => run(`/api/v1/meal-options/${option.id}`, { method: 'PATCH', body: { stock_portion: text } }, finish('Portion saved'));
    const toggleOption = (option) => run(`/api/v1/meal-options/${option.id}/${option.active ? 'deactivate' : 'activate'}`, { method: 'POST' }, { done: option.active ? 'Option hidden' : 'Option shown' });
    const moveOption = (group, option, direction) => {
        const ids = group.options.map((row) => row.id);
        const next = moveId(ids, option.id, direction);
        if (next !== ids) {
            run(`/api/v1/meal-option-groups/${group.id}/options/reorder`, { method: 'POST', body: { ids: next } });
        }
    };

    return (
        <AdminLayout title="Meal menu / Meals" requiredCapability="CATALOG_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Meals</h2>
            <p className="mb-4 max-w-2xl text-sm text-slate-400">
                A meal is a list of steps the cashier goes through, for example Viand, Side and Extra rice. Exactly one step sets the price of the meal, by the dish category of the dish
                chosen there. Set the prices on the Meal prices screen. Meals, steps and options are hidden, never deleted; changes apply to the next sale only.
            </p>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The meals could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}
            {meals === null && !failure && <Loading label="Loading meals" />}

            {meals !== null && (
                <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
                    <aside aria-label="Meals" className="space-y-3">
                        <ul className="overflow-hidden rounded-lg border border-slate-800">
                            {meals.map((row, index) => (
                                <li key={row.id} className="flex items-center gap-1 border-b border-slate-800/70 last:border-b-0">
                                    <button
                                        type="button"
                                        onClick={() => setSelectedId(row.id)}
                                        aria-current={row.id === selectedId ? 'true' : undefined}
                                        className={`min-h-11 flex-1 px-3 py-2 text-left text-sm lg:min-h-0 ${row.id === selectedId ? 'bg-emerald-950/60 text-emerald-300' : 'text-slate-200 hover:bg-slate-800'} ${row.active ? '' : 'text-slate-500'}`}
                                    >
                                        {row.name}
                                        {!row.active && <span className="ml-2 font-mono text-[10px] text-slate-500">HIDDEN</span>}
                                    </button>
                                    <MoveButtons name={row.name} index={index} count={meals.length} busy={busy} onMove={(direction) => moveMeal(row, direction)} />
                                </li>
                            ))}
                            {meals.length === 0 && <li className="px-3 py-4 text-sm text-slate-500">No meals yet.</li>}
                        </ul>
                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                            {editing?.newMeal ? (
                                <NameForm label="New meal name" submitLabel="Add meal" busy={busy} error={fieldError} onSubmit={addMeal} onCancel={() => setEditing(null)} />
                            ) : (
                                <button type="button" onClick={() => startEditing({ newMeal: true })} className={primaryButton}>
                                    Add meal
                                </button>
                            )}
                        </div>
                    </aside>

                    {meal && (
                        <section aria-label={`${meal.name} steps`} className="min-w-0 space-y-4">
                            <header className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-900 px-3 py-3">
                                <div className="min-w-0 flex-1">
                                    {editing?.renameMeal ? (
                                        <NameForm initial={meal.name} label="Meal name" submitLabel="Save" busy={busy} error={fieldError} onSubmit={renameMeal} onCancel={() => setEditing(null)} />
                                    ) : (
                                        <h3 className="text-base font-semibold text-slate-100">{meal.name}</h3>
                                    )}
                                </div>
                                {!editing?.renameMeal && (
                                    <div className="flex flex-wrap items-center gap-3 text-xs">
                                        <ActiveBadge active={meal.active} />
                                        <button type="button" onClick={() => startEditing({ renameMeal: true })} className={linkButton}>
                                            Rename
                                        </button>
                                        <button type="button" disabled={busy} onClick={toggleMeal} className={`min-h-11 hover:underline lg:min-h-0 ${meal.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}>
                                            {meal.active ? 'Hide' : 'Show'}
                                        </button>
                                        <Link to={`/admin/menu/prices/${meal.id}`} className={linkButton}>
                                            Prices
                                        </Link>
                                    </div>
                                )}
                                <div className="flex w-full items-center gap-2 text-sm text-slate-400">
                                    <label htmlFor="meal_menu_group">Shown on the till under</label>
                                    <select id="meal_menu_group" value={meal.menu_group_id ?? ''} disabled={busy} onChange={(event) => placeMeal(event.target.value)} className={`${inputClass(false)} w-auto max-w-xs py-1`}>
                                        <option value="">No menu group</option>
                                        {groupChoices.map((choice) => (
                                            <option key={choice.id} value={choice.id}>
                                                {choice.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </header>

                            {missing.length > 0 && (
                                <div role="status" className="rounded-md border border-amber-700/70 bg-amber-950/30 px-3 py-2 text-sm text-amber-200">
                                    No price yet for: {missing.join(', ')}. <Link to={`/admin/menu/prices/${meal.id}`} className="font-medium underline">Set the prices</Link> before this meal can be sold.
                                </div>
                            )}

                            <ol className="space-y-3" aria-label="Steps">
                                {meal.option_groups.map((group, groupIndex) => (
                                    <li key={group.id} className="overflow-hidden rounded-lg border border-slate-800">
                                        <div className="flex flex-wrap items-center gap-2 bg-slate-900 px-3 py-2">
                                            <div className="min-w-0 flex-1">
                                                <span className="mr-2 font-mono text-[11px] text-slate-500">STEP {groupIndex + 1}</span>
                                                <span className={`font-semibold ${group.active ? 'text-slate-100' : 'text-slate-500'}`}>{group.name}</span>
                                                <span className="ml-2 text-xs text-slate-400">
                                                    {group.kind === 'ADDON' ? 'Add-ons' : 'Dishes'} · {pickRule(group)}
                                                    {group.popup_after_choice ? ' · opens automatically' : ''}
                                                </span>
                                                {group.prices_the_meal && <span className="ml-2 rounded border border-emerald-700/70 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-emerald-300">PRICES THE MEAL</span>}
                                            </div>
                                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                                <ActiveBadge active={group.active} />
                                                <MoveButtons name={group.name} index={groupIndex} count={meal.option_groups.length} busy={busy} onMove={(direction) => moveGroup(group, direction)} />
                                                <button type="button" onClick={() => startEditing({ editGroup: group.id })} className={linkButton}>
                                                    Edit
                                                </button>
                                                {group.kind === 'CHOICE' && !group.prices_the_meal && group.active && (
                                                    <button type="button" disabled={busy} onClick={() => makePricing(group)} className={linkButton}>
                                                        Make this price the meal
                                                    </button>
                                                )}
                                                {!group.prices_the_meal && (
                                                    <button type="button" disabled={busy} onClick={() => toggleGroup(group)} className={`min-h-11 hover:underline lg:min-h-0 ${group.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}>
                                                        {group.active ? 'Hide' : 'Show'}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {editing?.editGroup === group.id && (
                                            <div className="p-3">
                                                <MealGroupForm group={group} busy={busy} error={fieldError} onSubmit={saveGroup(group)} onCancel={() => setEditing(null)} />
                                            </div>
                                        )}
                                        <ul aria-label={`${group.name} options`}>
                                            {group.options.map((option, index) => (
                                                <li key={option.id} className="flex flex-wrap items-center gap-2 border-t border-slate-800/70 px-3 py-2 pl-6">
                                                    <div className="min-w-0 flex-1">
                                                        {editing?.renameOption === option.id ? (
                                                            <NameForm initial={option.label ?? ''} label="Add-on label" submitLabel="Save" busy={busy} error={fieldError} onSubmit={renameOption(option)} onCancel={() => setEditing(null)} />
                                                        ) : (
                                                            <span className={option.active ? 'text-slate-100' : 'text-slate-500'}>
                                                                {option.label ?? option.dish_name}
                                                                {option.dish_id && <span className="ml-2 text-xs text-slate-500">{categories.find((category) => category.id === option.dish_category_id)?.name}</span>}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {editing?.portionOption === option.id && (
                                                        <div className="basis-full">
                                                            <NameForm initial={String(Number(option.stock_portion))} label={`Portions of ${option.dish_name} one plate uses`} submitLabel="Save portion" busy={busy} error={fieldError} onSubmit={savePortion(option)} onCancel={() => setEditing(null)} />
                                                        </div>
                                                    )}
                                                    {editing?.renameOption !== option.id && editing?.portionOption !== option.id && (
                                                        <div className="flex flex-wrap items-center gap-3 text-xs">
                                                            <ActiveBadge active={option.active} />
                                                            <MoveButtons name={option.label ?? option.dish_name} index={index} count={group.options.length} busy={busy} onMove={(direction) => moveOption(group, option, direction)} />
                                                            {option.dish_stock_mode === 'DAILY' && (
                                                                <button type="button" onClick={() => startEditing({ portionOption: option.id })} className={linkButton} aria-label={`Portion used by ${option.dish_name}: ${Number(option.stock_portion)}`}>
                                                                    Portion used: {Number(option.stock_portion)}
                                                                </button>
                                                            )}
                                                            {option.label && (
                                                                <button type="button" onClick={() => startEditing({ renameOption: option.id })} className={linkButton}>
                                                                    Rename
                                                                </button>
                                                            )}
                                                            <button type="button" disabled={busy} onClick={() => toggleOption(option)} className={`min-h-11 hover:underline lg:min-h-0 ${option.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}>
                                                                {option.active ? 'Hide' : 'Show'}
                                                            </button>
                                                        </div>
                                                    )}
                                                </li>
                                            ))}
                                            <li className="border-t border-slate-800/70 px-3 py-2 pl-6">
                                                {editing?.newOption === group.id ? (
                                                    <OptionAdder group={group} choices={dishChoicesFor(group, dishes, categories)} busy={busy} error={fieldError} onSubmit={addOption(group)} onCancel={() => setEditing(null)} />
                                                ) : (
                                                    <button type="button" onClick={() => startEditing({ newOption: group.id })} className={linkButton}>
                                                        {group.kind === 'ADDON' ? 'Add an add-on' : 'Offer a dish'}
                                                    </button>
                                                )}
                                            </li>
                                        </ul>
                                    </li>
                                ))}
                            </ol>

                            <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                                {editing?.newGroup ? (
                                    <MealGroupForm busy={busy} error={fieldError} onSubmit={addGroup} onCancel={() => setEditing(null)} />
                                ) : (
                                    <button type="button" onClick={() => startEditing({ newGroup: true })} className={primaryButton}>
                                        Add a step
                                    </button>
                                )}
                            </div>
                        </section>
                    )}
                </div>
            )}
        </AdminLayout>
    );
}

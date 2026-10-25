import { moneyText, pesos, toCents } from './posMoney';

/**
 * The till's meal menu (ADR-016 slice 1.3): pure helpers over the payload of GET /api/v1/menu. Nothing here knows a dish, a
 * price or a rule: the payload says which groups a meal has, how many options each takes and what each costs now, and the
 * server prices and validates the sale again when it is finalised (the preview here is only what the cashier sees first).
 *
 * @typedef {{id: string, label: string, sort_order: number, price: string|null, dish: {id: string, name: string, dish_category_id: string}|null}} MealOption
 * @typedef {{id: string, name: string, kind: 'CHOICE'|'ADDON', min_select: number, max_select: number, prices_the_meal: boolean, popup_after_choice: boolean, step_order: number, options: MealOption[]}} MealGroup
 * @typedef {{id: string, name: string, menu_group_id: string|null, menu_group_path: string|null, prices: Array<{dish_category_id: string, amount: string}>, option_groups: MealGroup[]}} Meal
 * @typedef {{[groupId: string]: string[]}} Picks  option ids picked, per group
 */

const byStep = (a, b) => a.step_order - b.step_order;

/** The CHOICE groups (dishes) in the order the cashier answers them. @returns {MealGroup[]} */
export function choiceGroups(meal) {
    return meal.option_groups.filter((group) => group.kind === 'CHOICE').sort(byStep);
}

/** The ADDON groups (extras such as rice) in step order. @returns {MealGroup[]} */
export function addonGroups(meal) {
    return meal.option_groups.filter((group) => group.kind === 'ADDON').sort(byStep);
}

/** The one group whose dish decides the meal's price. @returns {MealGroup|undefined} */
export function pricingGroup(meal) {
    return meal.option_groups.find((group) => group.prices_the_meal);
}

/**
 * The dish categories offered in the pricing group, in the payload's category order, each with the meal's price for it.
 * @returns {Array<{id: string, name: string, price: string|null}>}
 */
export function categoryChoices(meal, categories) {
    const group = pricingGroup(meal);
    if (!group) {
        return [];
    }
    const offered = new Set(group.options.map((option) => option.dish?.dish_category_id));
    return categories
        .filter((category) => offered.has(category.id))
        .map((category) => ({ id: category.id, name: category.name, price: meal.prices.find((price) => price.dish_category_id === category.id)?.amount ?? null }));
}

/** The dishes of one group, narrowed to a category when one is given. @returns {MealOption[]} */
export function dishesIn(group, categoryId = null) {
    return group.options.filter((option) => option.dish && (categoryId === null || option.dish.dish_category_id === categoryId));
}

/** The dish category an option belongs to. @returns {string|null} */
export function categoryOfOption(option) {
    return option?.dish?.dish_category_id ?? null;
}

/** Adds the option to the group's picks, or removes it when already picked; a single-pick group swaps instead. @returns {Picks} */
export function togglePick(picks, group, optionId) {
    const current = picks[group.id] ?? [];
    if (current.includes(optionId)) {
        return { ...picks, [group.id]: current.filter((id) => id !== optionId) };
    }
    if (group.max_select <= 1) {
        return { ...picks, [group.id]: [optionId] };
    }
    return current.length >= group.max_select ? picks : { ...picks, [group.id]: [...current, optionId] };
}

/** True when every group of the meal has between min_select and max_select picks. */
export function isComplete(meal, picks) {
    return meal.option_groups.every((group) => {
        const count = (picks[group.id] ?? []).length;
        return count >= group.min_select && count <= group.max_select;
    });
}

/** True when every CHOICE group is satisfied: the point at which a "popup after choice" group appears. */
export function choicesComplete(meal, picks) {
    return choiceGroups(meal).every((group) => {
        const count = (picks[group.id] ?? []).length;
        return count >= group.min_select && count <= group.max_select;
    });
}

function pickedOptions(group, picks) {
    const ids = picks[group.id] ?? [];
    return group.options.filter((option) => ids.includes(option.id));
}

/**
 * The meal price (the pricing group's pick) and the add-ons' prices, in centavos, from the payload's current prices.
 * @returns {{meal: bigint, addons: bigint, total: bigint}}
 */
export function previewCents(meal, picks) {
    const priced = pricingGroup(meal);
    const mealCents = priced ? pickedOptions(priced, picks).reduce((sum, option) => sum + (toCents(option.price) ?? 0n), 0n) : 0n;
    const addonCents = addonGroups(meal).reduce((sum, group) => sum + pickedOptions(group, picks).reduce((inner, option) => inner + (toCents(option.price) ?? 0n), 0n), 0n);
    return { meal: mealCents, addons: addonCents, total: mealCents + addonCents };
}

/** "from ₱60.00" for a tile: the lowest price in the payload, or null when the meal has none. */
export function fromPriceText(meal) {
    const cents = meal.prices.map((price) => toCents(price.amount)).filter((value) => value !== null);
    return cents.length === 0 ? null : `from ₱${pesos(cents.reduce((lowest, value) => (value < lowest ? value : lowest)))}`;
}

/**
 * The cart line for a finished selection. `meal` is exactly what the server takes under items[].meal (ids only, never a
 * price); `choices`/`addons` carry the names and prices the cart shows; `product` is the preview shape CartPanel and the
 * totals already understand (the combined price of one plate: the meal plus its add-ons).
 */
export function buildMealLine(meal, picks) {
    const choices = [];
    for (const group of choiceGroups(meal)) {
        for (const option of pickedOptions(group, picks)) {
            choices.push({ group_id: group.id, group_name: group.name, option_id: option.id, label: option.label });
        }
    }
    const addons = [];
    for (const group of addonGroups(meal)) {
        for (const option of pickedOptions(group, picks)) {
            addons.push({ group_id: group.id, group_name: group.name, option_id: option.id, label: option.label, price: option.price });
        }
    }
    const { total } = previewCents(meal, picks);

    return {
        key: mealLineKey(meal.id, choices, addons),
        product: { id: `meal:${meal.id}`, name: `${meal.name} - ${choices.map((choice) => choice.label).join(' + ')}`, selling_price: moneyText(total), unit_of_measure: 'order' },
        meal: {
            meal_id: meal.id,
            meal_name: meal.name,
            choices,
            addons,
        },
    };
}

/** The same meal with the same dishes and add-ons is one cart line; any difference is another. */
export function mealLineKey(mealId, choices, addons) {
    const ids = (list) => list.map((entry) => entry.option_id).sort().join(',');
    return `meal|${mealId}|${ids(choices)}|${ids(addons)}`;
}

/** The lines under a meal line in the cart: "Beef nilaga", "+ Whole rice ₱15.00". */
export function mealDetailLines(line) {
    return [
        ...line.meal.choices.map((choice) => choice.label),
        ...line.meal.addons.map((addon) => `+ ${addon.label}${addon.price ? ` ₱${addon.price}` : ''}`),
    ];
}

const MAX_PLATES = 99;

/**
 * How many plates a meal line's quantity means, or null when it is not a whole number from 1 to 99. The server takes one plate
 * per meal line (each plate has its own dish), so the till sends a quantity of N as N identical lines.
 */
export function mealPlates(quantity) {
    const text = String(quantity ?? '').trim();
    if (!/^\d{1,2}$/.test(text)) {
        return null;
    }
    const plates = Number(text);
    return plates >= 1 && plates <= MAX_PLATES ? plates : null;
}

/** The items[] entries for one cart line: a product line as before, a meal line as one entry per plate. No price is ever sent. */
export function requestItems(line, lineDiscountCents) {
    if (!line.meal) {
        return [
            {
                product_id: line.product.id,
                quantity: String(line.quantity),
                ...(lineDiscountCents > 0n ? { line_discount_amount: moneyText(lineDiscountCents) } : {}),
            },
        ];
    }
    const plate = {
        meal: {
            meal_id: line.meal.meal_id,
            choices: line.meal.choices.map((choice) => ({ group_id: choice.group_id, option_id: choice.option_id })),
            addons: line.meal.addons.map((addon) => addon.option_id),
        },
        quantity: '1',
    };
    return Array.from({ length: mealPlates(line.quantity) ?? 0 }, () => plate);
}

/**
 * The meals to show under the current tabs: with menu groups in use, a meal appears under its own group (and sub-group), under
 * its top group's tab and on All; a meal in no group appears on All only. With no groups in use (category tabs), on All only.
 * @param {Meal[]} meals
 * @param {Array<{id: string, children: Array<{id: string}>}>} menuTabs
 */
export function mealsForView(meals, { groupMode, menuTabs, groupId, subGroupId, categoryId }) {
    if (!groupMode) {
        return categoryId === '' ? meals : [];
    }
    if (groupId === '') {
        return meals;
    }
    if (subGroupId !== '') {
        return meals.filter((meal) => meal.menu_group_id === subGroupId);
    }
    const top = menuTabs.find((tab) => tab.id === groupId);
    const ids = new Set([groupId, ...(top?.children.map((child) => child.id) ?? [])]);
    return meals.filter((meal) => meal.menu_group_id !== null && ids.has(meal.menu_group_id));
}

/**
 * Pure helpers for the meal menu admin screens (ADR-016 slice 1.2): dishes, meals and their option groups, and the price grid.
 * Rows are what the Menu API returns (docs/05-api/openapi.yaml: DishCategory, Dish, Meal, MealOptionGroup, MealOption, MenuPrice).
 */

const byOrder = (a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name);

/** Categories in menu order, each with its dishes in menu order. Dishes of an unknown category are left out. */
export function dishesByCategory(categories, dishes) {
    return [...categories].sort(byOrder).map((category) => ({
        ...category,
        dishes: dishes.filter((dish) => dish.dish_category_id === category.id).sort(byOrder),
    }));
}

/** "Pick exactly 1", "Pick 1 to 3", "Optional", "Optional, up to 2": what the cashier is asked at this step. */
export function pickRule(group) {
    const { min_select: min, max_select: max } = group;
    if (min === 0) {
        return max === 1 ? 'Optional' : `Optional, up to ${max}`;
    }
    return min === max ? `Pick exactly ${min}` : `Pick ${min} to ${max}`;
}

/**
 * The dishes a CHOICE group can still be offered: active, and not already one of its options (hidden or not). Labelled with
 * their category so two similar dishes can be told apart.
 *
 * @returns {Array<{id: string, label: string}>}
 */
export function dishChoicesFor(group, dishes, categories) {
    const taken = new Set(group.options.map((option) => option.dish_id));
    const categoryName = new Map(categories.map((category) => [category.id, category.name]));
    return dishes
        .filter((dish) => dish.active && !taken.has(dish.id))
        .sort((a, b) => (categoryName.get(a.dish_category_id) ?? '').localeCompare(categoryName.get(b.dish_category_id) ?? '') || byOrder(a, b))
        .map((dish) => ({ id: dish.id, label: `${dish.name} (${categoryName.get(dish.dish_category_id) ?? 'no category'})` }));
}

/** What a meal still needs a price for, in words: ["Beef", "Whole rice"]. Empty when it is fully priced. */
export function unpricedNames(meal, categories) {
    const categoryName = new Map(categories.map((category) => [category.id, category.name]));
    const optionLabel = new Map(meal.option_groups.flatMap((group) => group.options.map((option) => [option.id, option.label ?? option.dish_name])));
    return [
        ...meal.pricing.unpriced_dish_category_ids.map((id) => categoryName.get(id) ?? 'a dish category'),
        ...meal.pricing.unpriced_option_ids.map((id) => optionLabel.get(id) ?? 'an add-on'),
    ];
}

/** "₱68.00" from the API's two-decimal string; "—" when there is no price. */
export function formatPeso(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }
    const [whole, fraction = '00'] = String(amount).split('.');
    return `₱${Number(whole).toLocaleString('en-PH')}.${`${fraction}00`.slice(0, 2)}`;
}

/** True when the typed amount (already normalised, or null when invalid) differs from the price in force. */
export function priceDiffers(current, normalizedTyped) {
    return normalizedTyped !== null && normalizedTyped !== (current?.amount ?? null);
}

/** The ISO instant for a datetime-local input value ("2026-11-01T08:00"), or undefined when empty/invalid (= start now). */
export function effectiveFromPayload(value) {
    if (!value) {
        return undefined;
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? undefined : date.toISOString();
}

/** "Nov 1, 2026, 8:00 AM" in the store's time zone (Asia/Manila). */
export function whenLabel(iso) {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' });
}

/** The price-grid row key used for history filtering and React keys. */
export function rowKey(row) {
    return row.kind === 'ADDON' ? `option:${row.meal_option_id}` : `category:${row.dish_category_id}`;
}

/** The query string that narrows the history to one grid row ('' for all rows). */
export function historyQuery(row) {
    if (!row) {
        return '';
    }
    return row.kind === 'ADDON' ? `meal_option_id=${row.meal_option_id}` : `dish_category_id=${row.dish_category_id}`;
}

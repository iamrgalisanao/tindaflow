import { describe, expect, it } from 'vitest';
import { buildMealLine, fromPriceText, mealDetailLines, mealLineKey, mealPlates, mealsForView, requestItems } from './mealMenu';

const meal = {
    id: 'm1', name: 'Standard Meal', menu_group_id: 'l1', menu_group_path: 'Lunch > Standard Meal',
    prices: [{ dish_category_id: 'beef', amount: '68.00' }, { dish_category_id: 'cpf', amount: '60.00' }],
    option_groups: [
        { id: 'g1', name: 'Viand', kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1, options: [{ id: 'o1', label: 'Beef nilaga', price: '68.00', dish: { id: 'd1', name: 'Beef nilaga', dish_category_id: 'beef' } }] },
        { id: 'g2', name: 'Extra rice', kind: 'ADDON', min_select: 0, max_select: 1, prices_the_meal: false, popup_after_choice: true, step_order: 2, options: [{ id: 'o2', label: 'Whole rice', price: '15.00', dish: null }] },
    ],
};

describe('cart lines for a meal', () => {
    it('the same dish and add-ons make the same key, any difference makes another', () => {
        const a = buildMealLine(meal, { g1: ['o1'], g2: ['o2'] });
        const b = buildMealLine(meal, { g1: ['o1'], g2: ['o2'] });
        const withoutRice = buildMealLine(meal, { g1: ['o1'] });
        expect(a.key).toBe(b.key);
        expect(a.key).not.toBe(withoutRice.key);
        expect(mealLineKey('m1', [{ option_id: 'b' }, { option_id: 'a' }], [])).toBe('meal|m1|a,b|');
    });

    it('previews one plate as the meal plus its add-ons and lists the selection under the line', () => {
        const line = buildMealLine(meal, { g1: ['o1'], g2: ['o2'] });
        expect(line.product.selling_price).toBe('83.00');
        expect(line.product.name).toBe('Standard Meal - Beef nilaga');
        expect(mealDetailLines(line)).toEqual(['Beef nilaga', '+ Whole rice ₱15.00']);
    });

    it('sends ids only, one item per plate, and never a price', () => {
        const line = { ...buildMealLine(meal, { g1: ['o1'], g2: ['o2'] }), quantity: '2', discount: '' };
        const items = requestItems(line, 0n);
        expect(items).toHaveLength(2);
        expect(items[0]).toEqual({ meal: { meal_id: 'm1', choices: [{ group_id: 'g1', option_id: 'o1' }], addons: ['o2'] }, quantity: '1' });
        expect(JSON.stringify(items)).not.toMatch(/price|68|15/);
    });

    it('sends an ordinary product exactly as before, with its discount', () => {
        const line = { key: 'p1', product: { id: 'p1', selling_price: '10.00' }, quantity: '3', discount: '' };
        expect(requestItems(line, 0n)).toEqual([{ product_id: 'p1', quantity: '3' }]);
        expect(requestItems(line, 250n)).toEqual([{ product_id: 'p1', quantity: '3', line_discount_amount: '2.50' }]);
    });

    it('a meal quantity is a whole number of plates from 1 to 99', () => {
        expect(mealPlates('3')).toBe(3);
        expect(mealPlates('99')).toBe(99);
        for (const bad of ['0', '100', '1.5', '', 'x', '-1']) {
            expect(mealPlates(bad)).toBeNull();
        }
    });
});

describe('tiles', () => {
    it('shows the lowest price', () => {
        expect(fromPriceText(meal)).toBe('from ₱60.00');
        expect(fromPriceText({ prices: [] })).toBeNull();
    });

    const tabs = [{ id: 'l', children: [{ id: 'l1' }, { id: 'l2' }] }, { id: 'b', children: [] }];
    const meals = [{ id: 'a', menu_group_id: 'l1' }, { id: 'b', menu_group_id: 'l2' }, { id: 'c', menu_group_id: null }];
    const ids = (list) => list.map((entry) => entry.id);

    it('puts a meal under its group, its top group and All, and an ungrouped meal on All only', () => {
        const view = (extra) => ids(mealsForView(meals, { groupMode: true, menuTabs: tabs, groupId: '', subGroupId: '', categoryId: '', ...extra }));
        expect(view({})).toEqual(['a', 'b', 'c']);
        expect(view({ groupId: 'l' })).toEqual(['a', 'b']);
        expect(view({ groupId: 'l', subGroupId: 'l2' })).toEqual(['b']);
        expect(view({ groupId: 'b' })).toEqual([]);
    });

    it('with category tabs the meals are on All only', () => {
        expect(ids(mealsForView(meals, { groupMode: false, menuTabs: [], groupId: '', subGroupId: '', categoryId: '' }))).toEqual(['a', 'b', 'c']);
        expect(mealsForView(meals, { groupMode: false, menuTabs: [], groupId: '', subGroupId: '', categoryId: 'c1' })).toEqual([]);
    });
});

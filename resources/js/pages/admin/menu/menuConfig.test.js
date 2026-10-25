import { describe, expect, it } from 'vitest';
import { dishChoicesFor, dishesByCategory, effectiveFromPayload, formatPeso, historyQuery, pickRule, priceDiffers, rowKey, unpricedNames } from './menuConfig';

const cat = (id, name, sort_order) => ({ id, name, sort_order, active: true });
const dish = (id, name, dish_category_id, sort_order = 0, active = true) => ({ id, name, dish_category_id, sort_order, active });

describe('menu configuration helpers', () => {
    it('groups dishes under their categories in menu order and ignores dishes of unknown categories', () => {
        const rows = dishesByCategory([cat('v', 'Vegetable', 2), cat('b', 'Beef', 1)], [dish('2', 'Mechado', 'b', 2), dish('1', 'Nilaga', 'b', 1), dish('3', 'Laing', 'v'), dish('4', 'Ghost', 'gone')]);
        expect(rows.map((row) => row.name)).toEqual(['Beef', 'Vegetable']);
        expect(rows[0].dishes.map((d) => d.name)).toEqual(['Nilaga', 'Mechado']);
        expect(rows[1].dishes.map((d) => d.name)).toEqual(['Laing']);
    });

    it('describes what a step asks of the cashier', () => {
        expect(pickRule({ min_select: 1, max_select: 1 })).toBe('Pick exactly 1');
        expect(pickRule({ min_select: 1, max_select: 3 })).toBe('Pick 1 to 3');
        expect(pickRule({ min_select: 2, max_select: 2 })).toBe('Pick exactly 2');
        expect(pickRule({ min_select: 0, max_select: 1 })).toBe('Optional');
        expect(pickRule({ min_select: 0, max_select: 2 })).toBe('Optional, up to 2');
    });

    it('offers only active dishes the group does not offer yet, labelled with their category', () => {
        const categories = [cat('b', 'Beef', 1), cat('v', 'Vegetable', 2)];
        const dishes = [dish('1', 'Nilaga', 'b'), dish('2', 'Laing', 'v'), dish('3', 'Retired', 'v', 0, false), dish('4', 'Pinakbet', 'v', 1)];
        const group = { options: [{ dish_id: '1', active: false }] };
        expect(dishChoicesFor(group, dishes, categories)).toEqual([
            { id: '2', label: 'Laing (Vegetable)' },
            { id: '4', label: 'Pinakbet (Vegetable)' },
        ]);
    });

    it('names what still has no price', () => {
        const meal = {
            option_groups: [{ options: [{ id: 'o1', label: 'Whole rice', dish_name: null }, { id: 'o2', label: null, dish_name: 'Nilaga' }] }],
            pricing: { unpriced_dish_category_ids: ['b', 'zz'], unpriced_option_ids: ['o1'] },
        };
        expect(unpricedNames(meal, [cat('b', 'Beef', 1)])).toEqual(['Beef', 'a dish category', 'Whole rice']);
        expect(unpricedNames({ option_groups: [], pricing: { unpriced_dish_category_ids: [], unpriced_option_ids: [] } }, [])).toEqual([]);
    });

    it('formats pesos from the two-decimal string', () => {
        expect(formatPeso('68.00')).toBe('₱68.00');
        expect(formatPeso('1500.5')).toBe('₱1,500.50');
        expect(formatPeso(null)).toBe('—');
    });

    it('sees whether a typed amount changes the price in force', () => {
        expect(priceDiffers({ amount: '68.00' }, '70.00')).toBe(true);
        expect(priceDiffers({ amount: '68.00' }, '68.00')).toBe(false);
        expect(priceDiffers(null, '68.00')).toBe(true);
        expect(priceDiffers({ amount: '68.00' }, null)).toBe(false);
    });

    it('turns a start time into an instant, or nothing (= now)', () => {
        expect(effectiveFromPayload('')).toBeUndefined();
        expect(effectiveFromPayload('not a date')).toBeUndefined();
        expect(effectiveFromPayload('2026-11-01T08:00')).toMatch(/^2026-11-01T\d\d:00:00\.000Z$/);
    });

    it('keys and filters grid rows', () => {
        const category = { kind: 'DISH_CATEGORY', dish_category_id: 'b', meal_option_id: null };
        const addon = { kind: 'ADDON', dish_category_id: null, meal_option_id: 'o1' };
        expect(rowKey(category)).toBe('category:b');
        expect(rowKey(addon)).toBe('option:o1');
        expect(historyQuery(category)).toBe('dish_category_id=b');
        expect(historyQuery(addon)).toBe('meal_option_id=o1');
        expect(historyQuery(null)).toBe('');
    });
});

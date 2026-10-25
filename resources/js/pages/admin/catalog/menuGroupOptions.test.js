import { describe, expect, it } from 'vitest';
import { groupPathLabel, groupTree, menuGroupOptions, moveId } from './menuGroupOptions';

const row = (id, name, sort_order, parent_id = null, active = true) => ({ id, name, sort_order, parent_id, active });
const rows = [row('l', 'Lunch', 2), row('b', 'Breakfast', 1), row('l2', 'Combo Meal', 2, 'l'), row('l1', 'Standard Meal', 1, 'l', false), row('x', 'Orphan', 1, 'gone')];

describe('menu group helpers', () => {
    it('builds the tree in order and ignores orphans', () => {
        const tree = groupTree(rows);
        expect(tree.map((top) => top.name)).toEqual(['Breakfast', 'Lunch']);
        expect(tree[1].children.map((child) => child.name)).toEqual(['Standard Meal', 'Combo Meal']);
    });

    it('lists the choices for a product with sub-groups nested under their group and hidden ones marked', () => {
        expect(menuGroupOptions(rows).map((option) => option.label)).toEqual(['Breakfast', 'Lunch', 'Lunch › Standard Meal (hidden)', 'Lunch › Combo Meal']);
    });

    it('names where a product sits', () => {
        expect(groupPathLabel(rows, 'l2')).toBe('Lunch › Combo Meal');
        expect(groupPathLabel(rows, 'b')).toBe('Breakfast');
        expect(groupPathLabel(rows, null)).toBeNull();
        expect(groupPathLabel(rows, 'unknown')).toBeNull();
    });

    it('moves an id one place and stops at the ends', () => {
        expect(moveId(['a', 'b', 'c'], 'b', -1)).toEqual(['b', 'a', 'c']);
        expect(moveId(['a', 'b', 'c'], 'b', 1)).toEqual(['a', 'c', 'b']);
        expect(moveId(['a', 'b', 'c'], 'a', -1)).toEqual(['a', 'b', 'c']);
        expect(moveId(['a', 'b', 'c'], 'c', 1)).toEqual(['a', 'b', 'c']);
        expect(moveId(['a'], 'zz', 1)).toEqual(['a']);
    });
});

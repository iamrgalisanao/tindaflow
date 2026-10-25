import { describe, expect, it } from 'vitest';
import { buildMenuTabs, groupFilter } from './menuGroups';

const row = (id, name, sort_order, parent_id = null, active = true) => ({ id, name, sort_order, parent_id, active });

describe('buildMenuTabs', () => {
    it('orders top groups and nests their sub-groups in order', () => {
        const tabs = buildMenuTabs([
            row('l', 'Lunch', 2),
            row('l2', 'Combo Meal', 2, 'l'),
            row('b', 'Breakfast', 1),
            row('l1', 'Standard Meal', 1, 'l'),
        ]);

        expect(tabs.map((tab) => tab.name)).toEqual(['Breakfast', 'Lunch']);
        expect(tabs[0].children).toEqual([]);
        expect(tabs[1].children.map((child) => child.name)).toEqual(['Standard Meal', 'Combo Meal']);
    });

    it('drops inactive groups, orphaned sub-groups and anything deeper than two levels', () => {
        const tabs = buildMenuTabs([
            row('a', 'Active', 1),
            row('h', 'Hidden', 2, null, false),
            row('a1', 'Hidden child', 1, 'a', false),
            row('o', 'Orphan', 1, 'missing'),
            row('deep', 'Too deep', 1, 'a1'),
        ]);

        expect(tabs).toEqual([{ id: 'a', name: 'Active', children: [] }]);
    });

    it('is empty (so the till keeps its category tabs) when the store has no groups, or the list is unusable', () => {
        expect(buildMenuTabs([])).toEqual([]);
        expect(buildMenuTabs(undefined)).toEqual([]);
    });
});

describe('groupFilter', () => {
    it('prefers the sub-group, falls back to the top group, and is empty for All', () => {
        expect(groupFilter('top', 'sub')).toBe('&menu_group_id=sub');
        expect(groupFilter('top', '')).toBe('&menu_group_id=top');
        expect(groupFilter('', '')).toBe('');
    });
});

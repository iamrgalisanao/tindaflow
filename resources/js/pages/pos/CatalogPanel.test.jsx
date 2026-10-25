import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const catalog = [
    { id: 'rice', sku: 'ALC-RICE', name: 'Plain rice', selling_price: '15.00', category_id: 'c2', active: true },
    { id: 'soda', sku: 'BEV-SODA', name: 'Soda', selling_price: '20.00', category_id: 'c2', active: true },
];

// The GET /api/v1/menu payload: Standard Meal sits in Lunch > Standard Meal, Combo Meal in Lunch > Combo Meal, Fiesta Meal in no group.
const optionGroups = (viandName) => [
    {
        id: 'g-viand', name: viandName, kind: 'CHOICE', min_select: 1, max_select: 1, prices_the_meal: true, popup_after_choice: false, step_order: 1,
        options: [
            { id: 'o-nilaga', label: 'Beef nilaga', sort_order: 1, price: '68.00', dish: { id: 'd1', name: 'Beef nilaga', dish_category_id: 'beef' } },
            { id: 'o-adobo', label: 'Chicken adobo', sort_order: 2, price: '60.00', dish: { id: 'd2', name: 'Chicken adobo', dish_category_id: 'cpf' } },
        ],
    },
];
const meals = [
    { id: 'm-std', name: 'Standard Meal', menu_group_id: 'l1', menu_group_path: 'Lunch > Standard Meal', prices: [{ dish_category_id: 'beef', amount: '68.00' }, { dish_category_id: 'cpf', amount: '60.00' }], option_groups: optionGroups('Viand') },
    { id: 'm-combo', name: 'Combo Meal', menu_group_id: 'l2', menu_group_path: 'Lunch > Combo Meal', prices: [{ dish_category_id: 'beef', amount: '85.00' }], option_groups: optionGroups('Main') },
    { id: 'm-fiesta', name: 'Fiesta Meal', menu_group_id: null, menu_group_path: null, prices: [{ dish_category_id: 'beef', amount: '90.00' }], option_groups: optionGroups('Viand') },
];
const dishCategories = [
    { id: 'beef', code: 'BEEF', name: 'Beef', sort_order: 1 },
    { id: 'cpf', code: 'CPF', name: 'Chicken/Pork/Fish', sort_order: 2 },
];
let menuFails = false;

// The store's active menu groups; empty means the till keeps its category tabs.
let menuGroups = [];
// Every product-list URL the panel asked for.
const productUrls = [];

vi.mock('../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        if (url.startsWith('/api/v1/menu?') || url === '/api/v1/menu') {
            return menuFails ? { ok: false, status: 500, body: {} } : { ok: true, body: { as_of: 'now', dish_categories: dishCategories, meals } };
        }
        if (url.startsWith('/api/v1/menu-groups')) {
            return { ok: true, body: { data: menuGroups } };
        }
        if (url.startsWith('/api/v1/categories')) {
            return { ok: true, body: { data: [{ id: 'c1', name: 'Meals' }, { id: 'c2', name: 'Ala carte' }] } };
        }
        productUrls.push(url);
        return { ok: true, body: { data: catalog, meta: { total: catalog.length } } };
    }),
}));

const { default: CatalogPanel } = await import('./CatalogPanel');

afterEach(() => {
    cleanup();
    menuGroups = [];
    menuFails = false;
    productUrls.length = 0;
});

const panel = (onAddMeal = () => {}) => <CatalogPanel search="" onSearchChange={() => {}} onSearch={() => {}} onClearSearch={() => {}} searchRef={{ current: null }} scanNotice={null} results={null} onAdd={() => {}} onAddMeal={onAddMeal} />;

describe('CatalogPanel meal menu', () => {
    it('shows the meal tiles on All with their lowest price, and adds a finished meal through onAddMeal', async () => {
        const onAddMeal = vi.fn();
        render(panel(onAddMeal));

        expect(await screen.findByText('Standard Meal')).toBeTruthy();
        expect(screen.getByText('from ₱60.00')).toBeTruthy();
        expect(await screen.findByText('Plain rice')).toBeTruthy();

        fireEvent.click(screen.getByText('Standard Meal'));
        fireEvent.click(await screen.findByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱68.00' }));

        expect(onAddMeal).toHaveBeenCalledWith(expect.objectContaining({ key: 'meal|m-std|o-nilaga|', meal: expect.objectContaining({ meal_id: 'm-std' }) }));
        expect(await screen.findByText('Plain rice')).toBeTruthy();
    });

    it('still lets the cashier sell products when the menu cannot be read', async () => {
        menuFails = true;
        render(panel());

        expect(await screen.findByText('Plain rice')).toBeTruthy();
        expect(screen.queryByText('Standard Meal')).toBeNull();
    });

    it('with menu groups, shows a meal under its own group and sub-group, its top group and All, and an ungrouped meal on All only', async () => {
        menuGroups = [group('l', 'Lunch', 2), group('l1', 'Standard Meal', 1, 'l'), group('l2', 'Combo Meal', 2, 'l'), group('b', 'Breakfast', 1)];
        render(panel());

        expect(await screen.findByRole('tablist', { name: 'Menu groups' })).toBeTruthy();
        expect(await screen.findByText('Fiesta Meal')).toBeTruthy();
        expect(screen.getByText('Standard Meal', { selector: 'span.text-slate-100' })).toBeTruthy();

        fireEvent.click(screen.getByRole('tab', { name: 'Lunch' }));
        await waitFor(() => expect(screen.queryByText('Fiesta Meal')).toBeNull());
        expect(screen.getByText('Standard Meal', { selector: 'span.text-slate-100' })).toBeTruthy();
        expect(screen.getByText('Combo Meal', { selector: 'span.text-slate-100' })).toBeTruthy();

        fireEvent.click(screen.getByRole('tab', { name: 'Combo Meal' }));
        await waitFor(() => expect(screen.queryByText('Standard Meal', { selector: 'span.text-slate-100' })).toBeNull());
        expect(screen.getByText('Combo Meal', { selector: 'span.text-slate-100' })).toBeTruthy();

        fireEvent.click(screen.getByRole('tab', { name: 'Breakfast' }));
        await waitFor(() => expect(screen.queryByText('Combo Meal', { selector: 'span.text-slate-100' })).toBeNull());
    });

    it('counts the meals on the selected tab, once, and no longer shows 0 over meal tiles', async () => {
        menuGroups = [group('l', 'Lunch', 2), group('l1', 'Standard Meal', 1, 'l'), group('l2', 'Combo Meal', 2, 'l'), group('b', 'Breakfast', 1)];
        render(panel());
        await screen.findByText('Fiesta Meal');
        const badge = (name) => screen.getByRole('tab', { name }).querySelector('span')?.textContent;

        // All: the catalog products plus the three meals.
        await waitFor(() => expect(badge(/^All/)).toBe(String(catalog.length + 3)));

        fireEvent.click(screen.getByRole('tab', { name: 'Lunch' }));
        await waitFor(() => expect(screen.queryByText('Fiesta Meal')).toBeNull());
        // Lunch holds its two meals once each (its sub-groups are not added again).
        await waitFor(() => expect(badge(/^Lunch/)).toBe(String(catalog.length + 2)));

        fireEvent.click(screen.getByRole('tab', { name: 'Combo Meal' }));
        await waitFor(() => expect(screen.queryByText('Standard Meal', { selector: 'span.text-slate-100' })).toBeNull());
        await waitFor(() => expect(badge(/^Combo Meal/)).toBe(String(catalog.length + 1)));
    });

    it('with category tabs only, shows the meals on All and not under a category', async () => {
        render(panel());

        expect(await screen.findByText('Fiesta Meal')).toBeTruthy();
        fireEvent.click(await screen.findByRole('tab', { name: 'Meals' }));
        await waitFor(() => expect(screen.queryByText('Fiesta Meal')).toBeNull());
    });
});

const group = (id, name, sort_order, parent_id = null) => ({ id, name, sort_order, parent_id, active: true });

describe('CatalogPanel menu groups', () => {
    it('keeps the category tabs when the store has no active menu groups', async () => {
        render(panel());

        expect(await screen.findByRole('tablist', { name: 'Categories' })).toBeTruthy();
        expect(screen.queryByRole('tablist', { name: 'Menu groups' })).toBeNull();
    });

    it('shows group tabs and sub-tabs instead, and filters the products by the chosen group', async () => {
        menuGroups = [group('b', 'Breakfast', 1), group('l', 'Lunch', 2), group('l1', 'Standard Meal', 1, 'l'), group('l2', 'Combo Meal', 2, 'l')];
        render(panel());

        expect(await screen.findByRole('tablist', { name: 'Menu groups' })).toBeTruthy();
        expect(screen.queryByRole('tablist', { name: 'Categories' })).toBeNull();
        expect(screen.queryByRole('tablist', { name: 'Lunch sub-groups' })).toBeNull();

        fireEvent.click(screen.getByRole('tab', { name: 'Lunch' }));
        expect(await screen.findByRole('tablist', { name: 'Lunch sub-groups' })).toBeTruthy();
        await waitFor(() => expect(productUrls.at(-1)).toContain('menu_group_id=l'));

        fireEvent.click(screen.getByRole('tab', { name: 'Combo Meal' }));
        await waitFor(() => expect(productUrls.at(-1)).toContain('menu_group_id=l2'));

        fireEvent.click(screen.getByRole('tab', { name: 'All Lunch' }));
        await waitFor(() => expect(productUrls.at(-1)).toContain('menu_group_id=l'));
        expect(productUrls.at(-1)).not.toContain('menu_group_id=l2');

        fireEvent.click(screen.getByRole('tab', { name: 'Breakfast' }));
        expect(screen.queryByRole('tablist', { name: 'Lunch sub-groups' })).toBeNull();
    });

    it('lists every product, grouped or not, under All', async () => {
        menuGroups = [group('b', 'Breakfast', 1)];
        render(panel());

        expect(await screen.findByText('Plain rice')).toBeTruthy();
        expect(productUrls.every((url) => !url.includes('menu_group_id'))).toBe(true);
    });
});

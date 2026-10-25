import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const meal = (sku, name, price) => ({ id: `id-${sku}`, sku, name, selling_price: price, category_id: 'c1', active: true });
const catalog = [
    meal('MEAL-STD-BEEF', 'Standard Meal - Beef', '68.00'),
    meal('MEAL-STD-OTHER', 'Standard Meal - Chicken/Pork/Fish', '60.00'),
    meal('MEAL-CMP-BEEF', 'Complete Meal - Beef', '85.00'),
    meal('MEAL-CMP-OTHER', 'Complete Meal - Chicken/Pork/Fish', '80.00'),
    { id: 'rice', sku: 'ALC-RICE', name: 'Plain rice', selling_price: '15.00', category_id: 'c2', active: true },
];

// The store's active menu groups; empty means the till keeps its category tabs.
let menuGroups = [];
// Every product-list URL the panel asked for.
const productUrls = [];

vi.mock('../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        if (url.startsWith('/api/v1/menu-groups')) {
            return { ok: true, body: { data: menuGroups } };
        }
        if (url.startsWith('/api/v1/categories')) {
            return { ok: true, body: { data: [{ id: 'c1', name: 'Meals' }, { id: 'c2', name: 'Ala carte' }] } };
        }
        productUrls.push(url);
        const search = new URL(url, 'http://x').searchParams.get('search');
        const data = search ? catalog.filter((p) => p.sku === search) : catalog;
        return { ok: true, body: { data, meta: { total: data.length } } };
    }),
}));

const { default: CatalogPanel } = await import('./CatalogPanel');

afterEach(() => {
    cleanup();
    menuGroups = [];
    productUrls.length = 0;
});

const panel = () => <CatalogPanel search="" onSearchChange={() => {}} onSearch={() => {}} onClearSearch={() => {}} searchRef={{ current: null }} scanNotice={null} results={null} onAdd={() => {}} />;

describe('CatalogPanel meal menus', () => {
    it('shows meal tiles, hides the protein products from the grid, and adds the chosen protein with the dish note', async () => {
        const onAdd = vi.fn();
        render(<CatalogPanel search="" onSearchChange={() => {}} onSearch={() => {}} onClearSearch={() => {}} searchRef={{ current: null }} scanNotice={null} results={null} onAdd={onAdd} />);

        expect(await screen.findByText('Standard Meal')).toBeTruthy();
        expect(await screen.findByText('Plain rice')).toBeTruthy();
        expect(screen.queryByText('Standard Meal - Beef')).toBeNull();
        expect(screen.getAllByText('from ₱60').length).toBe(1);

        fireEvent.click(screen.getByText('Standard Meal'));
        fireEvent.click(await screen.findByRole('button', { name: /^Beef/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beef nilaga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to order ₱68.00' }));

        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ sku: 'MEAL-STD-BEEF' }), 'Beef nilaga · Beef');
        expect(await screen.findByText('Plain rice')).toBeTruthy();
    });
});

describe('CatalogPanel menu groups', () => {
    const group = (id, name, sort_order, parent_id = null) => ({ id, name, sort_order, parent_id, active: true });

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

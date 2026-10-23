import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const meal = (sku, name, price) => ({ id: `id-${sku}`, sku, name, selling_price: price, category_id: 'c1', active: true });
const catalog = [
    meal('MEAL-STD-BEEF', 'Standard Meal - Beef', '68.00'),
    meal('MEAL-STD-OTHER', 'Standard Meal - Chicken/Pork/Fish', '60.00'),
    meal('MEAL-CMP-BEEF', 'Complete Meal - Beef', '85.00'),
    meal('MEAL-CMP-OTHER', 'Complete Meal - Chicken/Pork/Fish', '80.00'),
    { id: 'rice', sku: 'ALC-RICE', name: 'Plain rice', selling_price: '15.00', category_id: 'c2', active: true },
];

vi.mock('../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        if (url.startsWith('/api/v1/categories')) {
            return { ok: true, body: { data: [{ id: 'c1', name: 'Meals' }, { id: 'c2', name: 'Ala carte' }] } };
        }
        const search = new URL(url, 'http://x').searchParams.get('search');
        const data = search ? catalog.filter((p) => p.sku === search) : catalog;
        return { ok: true, body: { data, meta: { total: data.length } } };
    }),
}));

const { default: CatalogPanel } = await import('./CatalogPanel');

afterEach(cleanup);

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

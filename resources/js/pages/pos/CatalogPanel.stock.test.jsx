import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { stockFromResponse } from './stock';

// ADR-017 slice A: the tiles show what is left of each tracked product and, when the store blocks sales without stock, a product with none cannot be tapped.
const catalog = [
    { id: 'plenty', sku: 'BEV-A', name: 'Soda', selling_price: '20.00', category_id: 'c', active: true },
    { id: 'low', sku: 'BEV-B', name: 'Juice', selling_price: '25.00', category_id: 'c', active: true },
    { id: 'none', sku: 'BAK-C', name: 'Pandesal', selling_price: '5.00', category_id: 'c', active: true },
    { id: 'service', sku: 'SVC-D', name: 'Printing', selling_price: '3.00', category_id: 'c', active: true },
];

vi.mock('../../api', () => ({
    apiFetch: vi.fn(async (url) => {
        if (url.startsWith('/api/v1/menu-groups')) {
            return { ok: true, body: { data: [] } };
        }
        if (url.startsWith('/api/v1/menu')) {
            return { ok: true, body: { as_of: 'now', dish_categories: [], meals: [] } };
        }
        if (url.startsWith('/api/v1/categories')) {
            return { ok: true, body: { data: [{ id: 'c', name: 'Ala carte' }] } };
        }
        return { ok: true, body: { data: catalog, meta: { total: catalog.length } } };
    }),
}));

const { default: CatalogPanel } = await import('./CatalogPanel');

afterEach(cleanup);

const stockOf = (rules = {}) =>
    stockFromResponse({
        blocking: true,
        override_available: true,
        low_stock_default_threshold: 3,
        items: [
            { product_id: 'plenty', remaining: '12.000', threshold: 3 },
            { product_id: 'low', remaining: '2.000', threshold: 3 },
            { product_id: 'none', remaining: '0.000', threshold: 3 },
        ],
        ...rules,
    });

const panel = (stock, onAdd = () => {}) => (
    <CatalogPanel search="" onSearchChange={() => {}} onSearch={() => {}} onClearSearch={() => {}} searchRef={{ current: null }} scanNotice={null} results={null} onAdd={onAdd} onAddMeal={() => {}} stock={stock} />
);

describe('CatalogPanel stock marks', () => {
    it('shows how many are in stock, an amber "Only N left" at or below the threshold, and "No stock" at zero', async () => {
        render(panel(stockOf()));

        expect(await screen.findByText('12 in stock')).toBeTruthy();
        expect(screen.getByText('Only 2 left').getAttribute('data-stock')).toBe('low');
        expect(screen.getByText('No stock').getAttribute('data-stock')).toBe('out');
    });

    it('marks nothing on a product the store does not count', async () => {
        render(panel(stockOf()));
        await screen.findByText('Printing');

        const tile = screen.getByText('Printing').closest('button');
        expect(tile.querySelector('[data-stock]')).toBeNull();
        expect(tile.disabled).toBe(false);
    });

    it('when the store blocks sales, a tile with no stock is greyed and cannot be tapped, while the others add normally', async () => {
        const onAdd = vi.fn();
        render(panel(stockOf(), onAdd));
        await screen.findByText('No stock');

        const none = screen.getByRole('button', { name: 'Pandesal, No stock' });
        expect(none.disabled).toBe(true);
        fireEvent.click(none);
        expect(onAdd).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Juice, Only 2 left' }));
        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ id: 'low' }));
    });

    it('when the store does not block, "No stock" is only a label and the tile still adds', async () => {
        const onAdd = vi.fn();
        render(panel(stockOf({ blocking: false, override_available: false }), onAdd));
        await screen.findByText('No stock');

        const none = screen.getByRole('button', { name: 'Pandesal, No stock' });
        expect(none.disabled).toBe(false);
        fireEvent.click(none);
        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ id: 'none' }));
    });

    it('is exactly as before when the stock is not known', async () => {
        render(panel(undefined));

        expect(await screen.findByText('Soda')).toBeTruthy();
        expect(document.querySelector('[data-stock]')).toBeNull();
    });
});

// ADR-017 slice B: a daily item's tile reads "N left" or "Sold out" and never carries the amber "last 3" warning.
describe('CatalogPanel daily stock marks', () => {
    const dailyStock = (rules = {}) =>
        stockFromResponse({
            blocking: true,
            override_available: true,
            low_stock_default_threshold: 3,
            dishes: [{ dish_id: 'nilaga', name: 'Beef nilaga', remaining: '5.000' }],
            items: [
                { product_id: 'plenty', mode: 'DAILY', dish_id: 'nilaga', stock_portion: '2.000', remaining: '2.000', threshold: 0 },
                { product_id: 'low', mode: 'DAILY', dish_id: null, stock_portion: null, remaining: '1.000', threshold: 0 },
                { product_id: 'none', mode: 'DAILY', dish_id: null, stock_portion: null, remaining: '0.000', threshold: 0 },
            ],
            ...rules,
        });

    it('shows "N left" for a daily item even when only one is left, and "Sold out" at zero', async () => {
        render(panel(dailyStock()));

        expect((await screen.findByText('2 left')).getAttribute('data-stock')).toBe('ok');
        expect(screen.getByText('1 left').getAttribute('data-stock')).toBe('ok');
        expect(screen.getByText('Sold out').getAttribute('data-stock')).toBe('out');
    });

    it('a sold-out daily tile cannot be tapped when the store blocks, and can when it does not', async () => {
        const onAdd = vi.fn();
        const { unmount } = render(panel(dailyStock(), onAdd));
        await screen.findByText('Sold out');
        expect(screen.getByRole('button', { name: 'Pandesal, Sold out' }).disabled).toBe(true);
        unmount();

        render(panel(dailyStock({ blocking: false }), onAdd));
        await screen.findByText('Sold out');
        fireEvent.click(screen.getByRole('button', { name: 'Pandesal, Sold out' }));
        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ id: 'none' }));
    });
});

// ADR-017 slice C: a small "Check freshness" note on a tile whose newest delivery is older than the product's shelf life. Advisory: it never blocks.
describe('CatalogPanel check freshness note', () => {
    const freshStock = (blocking) =>
        stockFromResponse({
            blocking,
            override_available: true,
            low_stock_default_threshold: 3,
            items: [
                { product_id: 'plenty', remaining: '12.000', threshold: 3, check_freshness: true },
                { product_id: 'low', remaining: '2.000', threshold: 3, check_freshness: true },
                { product_id: 'none', remaining: '0.000', threshold: 3, check_freshness: false },
            ],
        });

    it('shows the note on flagged tiles only and keeps them tappable even when the store blocks sales', async () => {
        const onAdd = vi.fn();
        render(panel(freshStock(true), onAdd));

        await screen.findByText('12 in stock');
        expect(screen.getAllByText('Check freshness')).toHaveLength(2);
        const soda = screen.getByRole('button', { name: 'Soda, 12 in stock, check freshness' });
        expect(soda.disabled).toBe(false);
        expect(screen.getByRole('button', { name: 'Juice, Only 2 left, check freshness' }).querySelector('[data-freshness]')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Pandesal, No stock' }).querySelector('[data-freshness]')).toBeNull();
        fireEvent.click(soda);
        expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({ id: 'plenty' }));
    });

    it('shows nothing when the answer carries no flag', async () => {
        render(panel(stockOf()));
        await screen.findByText('12 in stock');

        expect(screen.queryByText('Check freshness')).toBeNull();
    });
});

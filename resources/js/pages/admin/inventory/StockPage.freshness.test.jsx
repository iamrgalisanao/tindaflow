import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import StockPage from './StockPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['STOCK_ADJUST'] } }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const meta = { page: 1, per_page: 25, total: 2, last_page: 1 };

const products = [
    { id: 'brw', sku: 'BRW-1', name: 'Brownies', unit_of_measure: 'pc', track_inventory: true, active: true, reorder_level: null },
    { id: 'rice', sku: 'RIC-1', name: 'Rice', unit_of_measure: 'kg', track_inventory: true, active: true, reorder_level: null },
];
const row = (id, extra = {}) => ({ product_id: id, location_id: 'loc', quantity_on_hand: '6.000', low_stock_threshold: 3, updated_at: '2026-10-27T01:00:00+08:00', check_freshness: false, last_received_on: null, days_old: null, shelf_life_days: null, ...extra });
const stale = row('brw', { check_freshness: true, last_received_on: '2026-10-20', days_old: 7, shelf_life_days: 3 });
const fine = row('rice', { last_received_on: '2026-10-26', shelf_life_days: 5 });

let calls;
beforeEach(() => {
    calls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url) => {
            calls.push(url);
            if (url.startsWith('/api/v1/terminal/current')) {
                return json({ terminal_code: 'T1' });
            }
            if (url.startsWith('/api/v1/products')) {
                return json({ data: products, meta: { page: 1, per_page: 100, total: 2, last_page: 1 } });
            }
            if (url.startsWith('/api/v1/inventory-locations')) {
                return json({ data: [{ id: 'loc', name: 'Main', is_default: true }], meta: { page: 1, per_page: 100, total: 1, last_page: 1 } });
            }
            if (url.includes('check_freshness=true')) {
                return json({ data: [stale], meta: { ...meta, total: 1 } });
            }
            return json({ data: [stale, fine], meta });
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const page = () =>
    render(
        <MemoryRouter>
            <StockPage />
        </MemoryRouter>,
    );

describe('StockPage check freshness', () => {
    it('badges only the flagged row with how old its last delivery is, and shows when each last arrived', async () => {
        page();

        const brownies = (await screen.findAllByText('Brownies'))[0].closest('tr');
        expect(within(brownies).getByText(/Check freshness/).textContent).toContain('7 days old');
        expect(within(brownies).getByText('2026-10-20')).toBeTruthy();
        const rice = screen.getAllByText('Rice')[0].closest('tr');
        expect(within(rice).queryByText(/Check freshness/)).toBeNull();
        expect(within(rice).getByText('2026-10-26')).toBeTruthy();
    });

    it('the "Check freshness only" filter asks the server for the flagged rows', async () => {
        page();
        await screen.findAllByText('Brownies');

        fireEvent.click(screen.getByRole('checkbox', { name: 'Check freshness only' }));

        await waitFor(() => expect(calls.some((url) => url.includes('/inventory/stock') && url.includes('check_freshness=true'))).toBe(true));
        await waitFor(() => expect(screen.queryAllByText('Rice')).toHaveLength(0));
        expect(screen.getAllByText('Brownies').length).toBeGreaterThan(0);
    });
});

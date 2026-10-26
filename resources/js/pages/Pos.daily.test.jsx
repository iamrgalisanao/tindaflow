import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// ADR-017 slice B at the register, through the real Pos component with the API mocked: the daily tiles and the pooled refusal of an add, the "no stock
// entered yet" banner, and the Today's stock tab.

const today = new Date().toISOString().slice(0, 10);
let availability;
let gridRequests;

const daily = (extra = {}) => ({
    blocking: true,
    override_available: true,
    low_stock_default_threshold: 3,
    stock_date: today,
    daily_configured: true,
    daily_entered: true,
    dishes: [{ dish_id: 'nilaga', name: 'Beef nilaga', remaining: '3.000', sold_out: false }],
    items: [{ product_id: 'ala', mode: 'DAILY', dish_id: 'nilaga', stock_portion: '2.000', remaining: '1.000', threshold: 0, low: false, sold_out: false }],
    ...extra,
});

vi.mock('../api', () => ({
    apiFetch: vi.fn(async (url) => {
        if (url === '/api/v1/store-setup/readiness') {
            return { ok: true, status: 200, body: { ready: true, document_mode: 'SLIP', checks: {} } };
        }
        if (url === '/api/v1/shifts/current') {
            return { ok: true, status: 200, body: { id: 's1', cashier_id: 'u1', fiscal_day_id: 'fd1', opened_at: new Date().toISOString() } };
        }
        if (url === '/api/v1/terminal/current') {
            return { ok: true, status: 200, body: { terminal_code: 'T-01' } };
        }
        if (url.startsWith('/api/v1/fiscal-days/')) {
            return { ok: true, status: 200, body: { business_date: today } };
        }
        if (url.startsWith('/api/v1/menu-groups')) {
            return { ok: true, status: 200, body: { data: [] } };
        }
        if (url.startsWith('/api/v1/menu')) {
            return { ok: true, status: 200, body: { as_of: 'now', dish_categories: [], meals: [] } };
        }
        if (url.startsWith('/api/v1/categories')) {
            return { ok: true, status: 200, body: { data: [] } };
        }
        if (url.startsWith('/api/v1/products')) {
            const data = [{ id: 'ala', sku: 'ALC-BEEF-NILAGA', name: 'Beef nilaga (ala carte)', selling_price: '120.00', category_id: null, active: true, unit_of_measure: 'order' }];
            return { ok: true, status: 200, body: { data, meta: { total: 1, last_page: 1 } } };
        }
        if (url === '/api/v1/stock/availability') {
            return { ok: true, status: 200, body: availability };
        }
        if (url === '/api/v1/daily-stock') {
            gridRequests += 1;
            return { ok: true, status: 200, body: { stock_date: today, location_id: 'l', can_enter_directly: false, rows: [{ kind: 'DISH', id: 'nilaga', name: 'Beef nilaga', group: 'Beef', pool_product_id: 'p', sku: null, cooked: '5.000', sold: '2.000', remaining: '3.000', yesterday_cooked: '0.000' }] } };
        }
        return { ok: true, status: 200, body: { data: [] } };
    }),
}));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 'u1', name: 'Cora Cashier', capabilities: [] }, setUser: () => {} }) }));

const { default: Pos } = await import('./Pos');

beforeEach(() => {
    availability = daily();
    gridRequests = 0;
});
afterEach(cleanup);

async function openRegister() {
    render(
        <MemoryRouter initialEntries={['/pos']}>
            <Pos />
        </MemoryRouter>,
    );
    fireEvent.click(await screen.findByRole('button', { name: /^Cash/ }));
    await screen.findByText('Beef nilaga (ala carte)');
}

describe('daily stock at the register', () => {
    it('reads "1 left" on the ala carte tile (3 portions at 2 each) and refuses a second order the dish cannot cover', async () => {
        await openRegister();
        expect((await screen.findByText('1 left')).getAttribute('data-stock')).toBe('ok');

        const tile = () => screen.getAllByText('Beef nilaga (ala carte)')[0].closest('button');
        fireEvent.click(tile());
        expect(within(screen.getByLabelText('Cart')).getByText(/Beef nilaga \(ala carte\)/)).toBeTruthy();
        fireEvent.click(tile());

        const notice = await screen.findByRole('alert');
        expect(notice.textContent).toContain('Beef nilaga (ala carte) is sold out');
        expect(within(notice).getByRole('button', { name: 'Add anyway, then ask a manager' })).toBeTruthy();
    });

    it('says "No stock entered for today yet" when the store blocks and nothing has been entered', async () => {
        availability = daily({ daily_entered: false, dishes: [{ dish_id: 'nilaga', name: 'Beef nilaga', remaining: '0.000', sold_out: true }] });
        await openRegister();

        expect((await screen.findByText(/No stock entered for today yet/)).getAttribute('role')).toBe('status');
    });

    it('shows no such banner once the day has an entry, or when the store does not block', async () => {
        await openRegister();
        await screen.findByText('1 left');
        expect(screen.queryByText(/No stock entered for today yet/)).toBeNull();
    });

    it("opens the Today's stock tab with the grid, where a cashier is told a manager approves the save", async () => {
        await openRegister();
        fireEvent.click(screen.getByRole('button', { name: "Today's stock" }));

        expect(await screen.findByRole('heading', { name: "Today's stock" })).toBeTruthy();
        await waitFor(() => expect(gridRequests).toBeGreaterThan(0));
        expect(await screen.findByLabelText('Cooked today, Beef nilaga')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Save stock (manager approves)' })).toBeTruthy();
    });
});

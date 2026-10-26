import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// ADR-017 slice A: stock at the till, driven through the real Pos component with the API mocked. The figures are advisory and the server decides:
// a tile shows what is left, an add the stock cannot cover is refused at the counter, a server refusal lists every short line and is never retried
// by the till, and a manager can approve ONE sale with their own code.

const today = new Date().toISOString().slice(0, 10);
const TOKEN = 'b'.repeat(64);

let availability; // the body of GET /stock/availability, or a function returning a response
let availabilityCalls;
let saleCalls;
let saleResults; // queue of answers to POST /sales
let grantCalls;
let grantResult;
let approversCalls;

const rules = (items, extra = {}) => ({ blocking: true, override_available: true, low_stock_default_threshold: 3, items, ...extra });
const riceOnly = (remaining) => rules([{ product_id: 'rice', remaining, threshold: 3, low: false, sold_out: false }]);

const refusal = (available = '1.000', extra = {}) => ({
    ok: false,
    status: 409,
    body: { error: { code: 'INSUFFICIENT_STOCK', message: 'Not enough stock for Plain rice', details: { override_available: true, shortfalls: [{ kind: 'PRODUCT', product_id: 'rice', name: 'Plain rice', mode: 'RUNNING', stock_date: null, required: '2.000', available }], ...extra } } },
});
const saleOk = () => ({
    ok: true,
    status: 201,
    body: {
        id: 'sale1', transaction_number: 'T-ULID', document_mode: 'SLIP', document_reference: 'T-01-000001', grand_total: '30.00', amount_tendered: '30.00', change: '0.00',
        items: [{ id: 'i1', quantity: '2.000', product_name_snapshot: 'Plain rice', net_line_amount: '30.00' }], slip: { id: 'slip1' }, employee: null,
    },
});

vi.mock('../api', () => ({
    apiFetch: vi.fn(async (url, options = {}) => {
        const method = options.method ?? 'GET';
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
            const data = [
                { id: 'rice', sku: 'ALC-RICE', name: 'Plain rice', selling_price: '15.00', category_id: null, active: true, unit_of_measure: 'order' },
                { id: 'service', sku: 'SVC', name: 'Printing', selling_price: '3.00', category_id: null, active: true, unit_of_measure: 'page' },
            ];
            return { ok: true, status: 200, body: { data, meta: { total: 2, last_page: 1 } } };
        }
        if (url === '/api/v1/stock/availability') {
            availabilityCalls.push(url);
            return typeof availability === 'function' ? availability() : { ok: true, status: 200, body: availability };
        }
        if (url === '/api/v1/stock-override-approvers') {
            approversCalls.push(url);
            return { ok: true, status: 200, body: { data: [{ id: 'm1', name: 'Mia Manager' }] } };
        }
        if (url === '/api/v1/stock-overrides' && method === 'POST') {
            grantCalls.push(options.body);
            return grantResult();
        }
        if (url === '/api/v1/sales' && method === 'POST') {
            saleCalls.push({ body: options.body, key: options.headers['Idempotency-Key'] });
            return (saleResults.shift() ?? saleOk)();
        }
        return { ok: true, status: 200, body: { data: [] } };
    }),
}));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 'u1', name: 'Cora Cashier', capabilities: [] }, setUser: () => {} }) }));

const { default: Pos } = await import('./Pos');

beforeEach(() => {
    availability = riceOnly('5.000');
    availabilityCalls = [];
    saleCalls = [];
    saleResults = [];
    grantCalls = [];
    approversCalls = [];
    grantResult = () => ({ ok: true, status: 201, body: { grant_id: 'g1', token: TOKEN, expires_at: '2026-10-26T10:05:00+08:00', approver: { id: 'm1', name: 'Mia Manager' }, shortfalls: [] } });
});

afterEach(cleanup);

async function openRegister() {
    render(
        <MemoryRouter initialEntries={['/pos']}>
            <Pos />
        </MemoryRouter>,
    );
    fireEvent.click(await screen.findByRole('button', { name: /^Cash/ }));
    await screen.findByText('Printing');
}
const tile = () => screen.getAllByText('Plain rice')[0].closest('button');
const cartRegion = () => screen.getByLabelText('Cart');
const tapRice = (times = 1) => Array.from({ length: times }).forEach(() => fireEvent.click(tile()));

async function sellTwoAndGetRefused() {
    await openRegister();
    tapRice(2);
    fireEvent.click(screen.getByRole('button', { name: /^Charge ₱30\.00$/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Complete sale/ }));
    return screen.findByRole('alert', { name: 'Not enough stock' });
}

describe('the tiles and the cart', () => {
    it('reads the availability once the register opens and marks the tile and the cart line', async () => {
        availability = riceOnly('5.000');
        await openRegister();

        await waitFor(() => expect(availabilityCalls.length).toBeGreaterThan(0));
        expect(await screen.findByText('5 in stock')).toBeTruthy();
        tapRice(3);
        // 5 in stock, 3 in the cart: 2 left after this sale, so the line turns amber (3 or fewer).
        expect(within(cartRegion()).getByText('2 left after this sale.').getAttribute('data-stock')).toBe('low');
    });

    it('shows "Only N left" on the tile at or below the threshold and greys a tile with no stock', async () => {
        availability = rules([
            { product_id: 'rice', remaining: '0.000', threshold: 3, low: false, sold_out: true },
        ]);
        await openRegister();

        expect(await screen.findByText('No stock')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Plain rice, No stock' }).disabled).toBe(true);
    });

    it('refuses an add the stock cannot cover, right at the counter, and says how many are left', async () => {
        availability = riceOnly('2.000');
        await openRegister();
        await screen.findByText('Only 2 left');

        tapRice(3);

        const notice = await screen.findByRole('alert');
        expect(notice.textContent).toContain('Only 2 left of Plain rice');
        expect(notice.textContent).toContain('2 are already in the cart');
        expect(within(cartRegion()).getByLabelText('Quantity of Plain rice').value).toBe('2');
    });

    it('refuses the "+" in the cart the same way', async () => {
        availability = riceOnly('1.000');
        await openRegister();
        await screen.findByText('Only 1 left');
        tapRice(1);

        fireEvent.click(screen.getByRole('button', { name: 'One more Plain rice' }));

        expect((await screen.findByRole('alert')).textContent).toContain('Only 1 left of Plain rice');
        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('1');
    });

    it('offers "Add anyway, then ask a manager" only when the store allows the override', async () => {
        availability = riceOnly('1.000');
        await openRegister();
        await screen.findByText('Only 1 left');
        tapRice(2);
        const notice = await screen.findByRole('alert');
        fireEvent.click(within(notice).getByRole('button', { name: 'Add anyway, then ask a manager' }));

        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('2');
        expect(within(cartRegion()).getByRole('alert').textContent).toContain('Only 1 left.');
    });

    it('does not offer it when overrides are switched off', async () => {
        availability = riceOnly('1.000');
        availability.override_available = false;
        await openRegister();
        await screen.findByText('Only 1 left');
        tapRice(2);

        const notice = await screen.findByRole('alert');
        expect(within(notice).queryByRole('button', { name: /Add anyway/ })).toBeNull();
        expect(within(notice).getByRole('button', { name: 'OK' })).toBeTruthy();
    });

    it('with blocking off, nothing is refused at the counter and the cart only warns', async () => {
        availability = riceOnly('1.000');
        availability.blocking = false;
        availability.override_available = false;
        await openRegister();
        await screen.findByText('Only 1 left');

        tapRice(3);

        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('3');
        expect(within(cartRegion()).getByRole('alert').textContent).toContain('Stock will go below zero');
    });

    it('sells exactly as before when the availability cannot be read', async () => {
        availability = () => ({ ok: false, status: 500, body: null });
        await openRegister();

        tapRice(3);

        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('3');
        expect(document.querySelector('[data-stock]')).toBeNull();
    });
});

describe('a sale the server refuses for stock', () => {
    it('lists every short line, charges nothing, does not retry by itself, and sends no approval token', async () => {
        saleResults = [() => refusal('1.000')];
        // The till believes there is plenty; the server knows better (a stale figure).
        availability = riceOnly('9.000');

        const panel = await sellTwoAndGetRefused();

        expect(within(panel).getByText('Plain rice')).toBeTruthy();
        expect(panel.textContent).toContain('needs 2, only 1 in stock');
        expect(panel.textContent).toContain('Nothing was charged');
        expect(saleCalls).toHaveLength(1);
        expect(saleCalls[0].body.stock_override_token).toBeUndefined();
        await new Promise((resolve) => setTimeout(resolve, 600)); // the till must not retry a refusal on its own
        expect(saleCalls).toHaveLength(1);
        expect(screen.queryByText('Sale complete')).toBeNull();
        // The figures are refreshed after a refusal.
        expect(availabilityCalls.length).toBeGreaterThanOrEqual(2);
    });

    it('"Remove item" takes the short line out of the cart and clears the panel', async () => {
        saleResults = [() => refusal('0.000')];
        availability = riceOnly('9.000');
        const panel = await sellTwoAndGetRefused();

        expect(panel.textContent).toContain('no stock');
        fireEvent.click(within(panel).getByRole('button', { name: 'Remove Plain rice from the cart' }));

        await waitFor(() => expect(screen.queryByRole('alert', { name: 'Not enough stock' })).toBeNull());
        expect(screen.queryByLabelText('Quantity of Plain rice')).toBeNull();
    });

    it('"Fix the cart" dismisses the panel and a changed cart ends it too', async () => {
        saleResults = [() => refusal('1.000'), () => refusal('1.000')];
        availability = riceOnly('9.000');
        const panel = await sellTwoAndGetRefused();

        fireEvent.click(within(panel).getByRole('button', { name: 'Fix the cart' }));
        expect(screen.queryByRole('alert', { name: 'Not enough stock' })).toBeNull();
    });

    it('does not offer the manager when the store has switched the override off', async () => {
        saleResults = [() => refusal('1.000', { override_available: false })];
        availability = riceOnly('9.000');
        const panel = await sellTwoAndGetRefused();

        expect(within(panel).queryByRole('button', { name: 'Ask a manager to approve' })).toBeNull();
    });
});

describe('asking a manager to approve a sale without stock', () => {
    const open = async () => {
        const panel = await sellTwoAndGetRefused();
        fireEvent.click(within(panel).getByRole('button', { name: 'Ask a manager to approve' }));

        return screen.findByRole('group', { name: 'Manager approval' });
    };
    const type = (panel, digits) => [...digits].forEach((digit) => fireEvent.click(within(within(panel).getByRole('group', { name: 'Code keypad' })).getByRole('button', { name: digit })));

    it('lists the managers by name, takes the code masked, and sends the sale again with the single-use approval', async () => {
        saleResults = [() => refusal('1.000')];
        availability = riceOnly('9.000');
        const approval = await open();

        fireEvent.click(await within(approval).findByRole('button', { name: 'Mia Manager' }));
        type(approval, '482916');
        const box = within(approval).getByLabelText("Manager's code");
        expect(box.getAttribute('type')).toBe('password'); // the cashier never reads it
        expect(approval.textContent).not.toContain('482916');
        fireEvent.click(within(approval).getByRole('button', { name: 'Approve and sell' }));

        expect(await screen.findByText('Sale complete')).toBeTruthy();
        expect(approversCalls).toEqual(['/api/v1/stock-override-approvers']);
        expect(grantCalls).toEqual([{ approver_id: 'm1', code: '482916', items: [{ product_id: 'rice', quantity: '2' }] }]);
        expect(saleCalls).toHaveLength(2);
        expect(saleCalls[1].body.stock_override_token).toBe(TOKEN);
        expect(saleCalls[1].body.items).toEqual(saleCalls[0].body.items);
        expect(saleCalls[1].key).not.toBe(saleCalls[0].key); // a different request, so a different key
        expect(document.body.textContent).not.toContain(TOKEN);
    });

    it('a code that does not work says only that, clears the box, and sends nothing', async () => {
        saleResults = [() => refusal('1.000')];
        grantResult = () => ({ ok: false, status: 422, body: { error: { code: 'STOCK_OVERRIDE_INVALID', message: 'That code did not work.' } } });
        availability = riceOnly('9.000');
        const approval = await open();

        fireEvent.click(await within(approval).findByRole('button', { name: 'Mia Manager' }));
        type(approval, '000001');
        fireEvent.click(within(approval).getByRole('button', { name: 'Approve and sell' }));

        expect((await within(approval).findByRole('alert')).textContent).toBe('That code did not work.');
        expect(within(approval).getByLabelText("Manager's code").value).toBe('');
        expect(saleCalls).toHaveLength(1);
    });

    it('an approval the server then refuses is dropped and the cashier can ask again', async () => {
        saleResults = [() => refusal('1.000'), () => ({ ok: false, status: 409, body: { error: { code: 'STOCK_OVERRIDE_EXPIRED', message: 'This approval has expired or was already used. Ask a manager to approve again.' } } })];
        availability = riceOnly('9.000');
        const approval = await open();

        fireEvent.click(await within(approval).findByRole('button', { name: 'Mia Manager' }));
        type(approval, '482916');
        fireEvent.click(within(approval).getByRole('button', { name: 'Approve and sell' }));

        expect(await screen.findByText(/has expired or was already used/)).toBeTruthy();
        expect(screen.queryByText('Sale complete')).toBeNull();
        expect(saleCalls).toHaveLength(2);
        // Still on the register with the refusal panel, so the cashier can ask again.
        expect(screen.getByRole('alert', { name: 'Not enough stock' })).toBeTruthy();
    });
});

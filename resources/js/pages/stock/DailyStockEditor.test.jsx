import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DailyStockEditor from './DailyStockEditor';

// ADR-017 slice B, client decisions 5 and 6: the morning grid. A manager saves directly; a cashier's save goes through a manager's code. Copy yesterday only
// fills the boxes.
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

const grid = (rows) => ({ stock_date: '2026-10-27', location_id: 'l', can_enter_directly: true, rows });
const row = (kind, id, name, extra = {}) => ({ kind, id, name, group: kind === 'DISH' ? 'Beef' : null, pool_product_id: `pool-${id}`, sku: null, cooked: '0.000', sold: '0.000', remaining: '0.000', yesterday_cooked: '0.000', ...extra });

let calls;
let rows;
let postResult;
let grantResult;

beforeEach(() => {
    calls = [];
    rows = [row('DISH', 'nilaga', 'Beef nilaga', { cooked: '20.000', sold: '6.000', remaining: '14.000', yesterday_cooked: '18.000' }), row('DISH', 'adobo', 'Beef adobo', { yesterday_cooked: '12.000' }), row('PRODUCT', 'siopao', 'Siopao')];
    postResult = null;
    grantResult = null;
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            const method = options.method ?? 'GET';
            calls.push({ url, method, body: options.body ? JSON.parse(options.body) : undefined, headers: options.headers });
            if (url === '/api/v1/daily-stock' && method === 'GET') {
                return json(grid(rows));
            }
            if (url === '/api/v1/daily-stock/approvers') {
                return json({ data: [{ id: 'mgr', name: 'Maria Manager' }] });
            }
            if (url === '/api/v1/daily-stock/approvals') {
                return grantResult ? grantResult() : json({ grant_id: 'g', token: 'a'.repeat(64), stock_date: '2026-10-27', expires_at: 'x', approver: { id: 'mgr', name: 'Maria Manager' } }, 201);
            }
            if (url === '/api/v1/daily-stock/entries') {
                return postResult ? postResult() : json({ stock_date: '2026-10-27', lines: [], grid: grid(rows.map((entry) => (entry.id === 'adobo' ? { ...entry, cooked: '15.000', remaining: '15.000' } : entry))) }, 201);
            }
            return json({}, 404);
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const posts = () => calls.filter((call) => call.url === '/api/v1/daily-stock/entries');
const cooked = (name) => screen.getByLabelText(`Cooked today, ${name}`);

describe('the grid', () => {
    it('lists every dish and daily product with what is cooked, sold and left, grouped by category', async () => {
        render(<DailyStockEditor canEnterDirectly />);

        expect(await screen.findByText('Beef nilaga')).toBeTruthy();
        expect(screen.getByRole('table', { name: /Beef/ })).toBeTruthy();
        expect(screen.getByRole('table', { name: /Other items/ })).toBeTruthy();
        const nilaga = screen.getByText('Beef nilaga').closest('tr');
        expect(within(nilaga).getByText('6')).toBeTruthy();
        expect(within(nilaga).getByText('14')).toBeTruthy();
        expect(screen.getByText(/yesterday 18/)).toBeTruthy();
    });

    it('copy yesterday fills the boxes of rows with nothing cooked and saves nothing', async () => {
        render(<DailyStockEditor canEnterDirectly />);
        await screen.findByText('Beef nilaga');

        fireEvent.click(screen.getByRole('button', { name: "Copy yesterday's quantities" }));

        expect(cooked('Beef adobo').value).toBe('12');
        expect(cooked('Beef nilaga').value).toBe('');
        expect(posts()).toHaveLength(0);
        expect(screen.getByRole('status').textContent).toContain('Nothing is saved until you press Save');
    });

    it('says so when no dish counts daily stock', async () => {
        rows = [];
        render(<DailyStockEditor canEnterDirectly />);

        expect(await screen.findByText(/No dish or product counts daily stock yet/)).toBeTruthy();
    });
});

describe('a manager', () => {
    it('saves directly: one request, the changed totals and a second batch, with an idempotency key and no approval', async () => {
        render(<DailyStockEditor canEnterDirectly />);
        await screen.findByText('Beef nilaga');

        expect(screen.getByRole('button', { name: 'Save stock' }).disabled).toBe(true);
        fireEvent.change(cooked('Beef adobo'), { target: { value: '15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add 10 to Siopao' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add 5 to Siopao' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save stock' }));

        await waitFor(() => expect(posts()).toHaveLength(1));
        expect(posts()[0].body).toEqual({ lines: [{ dish_id: 'adobo', cooked: '15' }, { product_id: 'siopao', add: '15' }] });
        expect(posts()[0].headers['Idempotency-Key']).toBeTruthy();
        expect(calls.some((call) => call.url === '/api/v1/daily-stock/approvals')).toBe(false);
        expect((await screen.findByText('Saved.')).getAttribute('role')).toBe('status');
        expect(cooked('Beef adobo').value).toBe('');
    });

    it('asks why before lowering a total and sends the reason', async () => {
        render(<DailyStockEditor canEnterDirectly />);
        await screen.findByText('Beef nilaga');

        fireEvent.change(cooked('Beef nilaga'), { target: { value: '15' } });
        const save = screen.getByRole('button', { name: 'Save stock' });
        expect(save.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText(/Why is a quantity being lowered/), { target: { value: 'Burnt batch' } });
        expect(save.disabled).toBe(false);
        fireEvent.click(save);

        await waitFor(() => expect(posts()).toHaveLength(1));
        expect(posts()[0].body).toEqual({ lines: [{ dish_id: 'nilaga', cooked: '15' }], reason: 'Burnt batch' });
    });

    it('shows the server\'s refusal to go below what is sold and keeps the boxes', async () => {
        postResult = () => json({ error: { code: 'DAILY_STOCK_BELOW_SOLD', message: 'x', details: { items: [{ name: 'Beef nilaga' }] } } }, 422);
        render(<DailyStockEditor canEnterDirectly />);
        await screen.findByText('Beef nilaga');
        fireEvent.change(cooked('Beef nilaga'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText(/Why is a quantity being lowered/), { target: { value: 'Miscount' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save stock' }));

        expect((await screen.findByRole('alert')).textContent).toBe('Beef nilaga cannot go below what is already sold today. Nothing was saved.');
        expect(cooked('Beef nilaga').value).toBe('2');
    });
});

describe('a cashier', () => {
    const askForTheManager = async () => {
        render(<DailyStockEditor canEnterDirectly={false} />);
        await screen.findByText('Beef nilaga');
        fireEvent.change(cooked('Beef adobo'), { target: { value: '15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save stock (manager approves)' }));
        await screen.findByRole('group', { name: 'Manager approval' });
    };
    const typeCode = (code) => {
        for (const digit of code) {
            fireEvent.click(within(screen.getByRole('group', { name: 'Code keypad' })).getByRole('button', { name: digit }));
        }
    };

    it('does not save on her own: Save asks a manager to approve, and nothing is sent yet', async () => {
        await askForTheManager();

        expect(posts()).toHaveLength(0);
        expect(screen.getByText('Maria Manager')).toBeTruthy();
    });

    it("saves with the manager's single-use approval and names the manager afterwards", async () => {
        await askForTheManager();

        fireEvent.click(await screen.findByRole('button', { name: 'Maria Manager' }));
        typeCode('482916');
        fireEvent.click(screen.getByRole('button', { name: 'Approve and save' }));

        await waitFor(() => expect(posts()).toHaveLength(1));
        const grant = calls.find((call) => call.url === '/api/v1/daily-stock/approvals');
        expect(grant.body).toEqual({ approver_id: 'mgr', code: '482916' });
        expect(posts()[0].body).toEqual({ lines: [{ dish_id: 'adobo', cooked: '15' }], stock_entry_token: 'a'.repeat(64) });
        expect((await screen.findByText('Saved. Approved by Maria Manager.')).getAttribute('role')).toBe('status');
        expect(screen.queryByRole('group', { name: 'Manager approval' })).toBeNull();
    });

    it('a wrong code says only that it did not work and sends no entry', async () => {
        grantResult = () => json({ error: { code: 'STOCK_ENTRY_APPROVAL_INVALID', message: 'That code did not work.' } }, 422);
        await askForTheManager();

        fireEvent.click(await screen.findByRole('button', { name: 'Maria Manager' }));
        typeCode('000001');
        fireEvent.click(screen.getByRole('button', { name: 'Approve and save' }));

        expect((await screen.findByText('That code did not work.')).getAttribute('role')).toBe('alert');
        expect(posts()).toHaveLength(0);
        expect(screen.getByLabelText("Manager's code").value).toBe('');
    });
});

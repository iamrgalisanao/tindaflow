import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import StockRulesPage from './StockRulesPage';

vi.mock('./AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['STORE_SETTINGS_MANAGE'] } }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const preflight = { unsellable_count: 2, tracked_count: 5, sample: [{ product_id: 'a', name: 'Pandesal', sku: 'BAK-1' }, { product_id: 'b', name: 'Soda', sku: 'BEV-1' }] };

let calls;
let rules;
let putResult;
beforeEach(() => {
    calls = [];
    rules = { block_sale_without_stock: false, stock_override_enabled: true, low_stock_default_threshold: 3, preflight };
    putResult = null;
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (options.method === 'PUT') {
                if (putResult) {
                    return putResult();
                }
                rules = { ...rules, ...JSON.parse(options.body) };
                return json(rules);
            }
            return json(rules);
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
            <StockRulesPage />
        </MemoryRouter>,
    );
const puts = () => calls.filter((call) => call.method === 'PUT');

describe('StockRulesPage', () => {
    it('shows the three rules as saved and, before blocking is on, how many items would be unsellable', async () => {
        page();

        const block = await screen.findByRole('checkbox', { name: /Do not allow a sale when there is not enough stock/ });
        expect(block.checked).toBe(false);
        expect(screen.getByRole('checkbox', { name: /Let a manager approve one blocked sale/ }).checked).toBe(true);
        expect(screen.getByLabelText('Warn when this many or fewer are left').value).toBe('3');
        const status = screen.getByRole('status', { name: 'Items without stock' });
        expect(status.textContent).toContain('2 of 5 tracked items have no stock right now');
        expect(status.textContent).toContain('If you turn blocking on');
        expect(within(status).getByText('Pandesal')).toBeTruthy();
    });

    it('asks for confirmation naming the count before turning blocking on, and sends the three values', async () => {
        page();
        fireEvent.click(await screen.findByRole('checkbox', { name: /Do not allow a sale/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save stock rules' }));

        const confirm = await screen.findByRole('group', { name: 'Confirm blocking' });
        expect(confirm.textContent).toContain('2 items will be unsellable until stock is entered');
        expect(puts()).toHaveLength(0);

        fireEvent.click(within(confirm).getByRole('button', { name: 'Yes, turn on blocking' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]).toMatchObject({ url: '/api/v1/stock-rules', body: { block_sale_without_stock: true, stock_override_enabled: true, low_stock_default_threshold: 3 } });
        expect(await screen.findByText(/Sales without stock are now blocked/)).toBeTruthy();
    });

    it('cancelling the confirmation saves nothing', async () => {
        page();
        fireEvent.click(await screen.findByRole('checkbox', { name: /Do not allow a sale/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save stock rules' }));
        fireEvent.click(within(await screen.findByRole('group', { name: 'Confirm blocking' })).getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('group', { name: 'Confirm blocking' })).toBeNull();
        expect(puts()).toHaveLength(0);
    });

    it('turning blocking off or changing the number saves at once, without a confirmation', async () => {
        rules = { ...rules, block_sale_without_stock: true };
        page();
        const input = await screen.findByLabelText('Warn when this many or fewer are left');
        fireEvent.change(input, { target: { value: '5' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /Let a manager approve one blocked sale/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save stock rules' }));

        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0].body).toEqual({ block_sale_without_stock: true, stock_override_enabled: false, low_stock_default_threshold: 5 });
    });

    it('does not offer to save until something changed, and refuses a number that is not 0 to 1000', async () => {
        page();
        const save = await screen.findByRole('button', { name: 'Save stock rules' });
        expect(save.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('Warn when this many or fewer are left'), { target: { value: 'many' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save stock rules' }));
        expect(screen.getByLabelText('Warn when this many or fewer are left').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByRole('alert').textContent).toContain('whole number from 0 to 1000');
        expect(puts()).toHaveLength(0);
    });

    it('shows a server refusal and keeps the typed values', async () => {
        putResult = () => json({ error: { code: 'VALIDATION_FAILED', message: 'x', details: { low_stock_default_threshold: ['Use a whole number from 0 to 1000.'] } } }, 422);
        page();
        fireEvent.change(await screen.findByLabelText('Warn when this many or fewer are left'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save stock rules' }));

        expect((await screen.findByRole('alert')).textContent).toContain('Use a whole number from 0 to 1000.');
        expect(screen.getByLabelText('Warn when this many or fewer are left').value).toBe('7');
    });

    it('tells a user who may not see the rules, instead of showing a blank page', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json({ error: { code: 'FORBIDDEN', message: 'no' } }, 403)));
        page();

        expect((await screen.findByRole('alert')).textContent).toContain('Your role does not allow this action');
    });
});

import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// ADR-016 section 6, slice 3.3: asking a manager to approve an over-limit credit sale at the till, driven through the real Pos component
// with the API mocked. The code is typed by the manager on the keypad, masked; the cashier never sees it; the approval is one sale only.

const today = new Date().toISOString().slice(0, 10);
const credit = { limit: '1500.00', used: '1490.00', remaining: '10.00', period_start: '2026-10-16', period_end: '2026-10-31' };
const ana = { id: 'e1', employee_code: 'TSC00012', employee_type: 'DIRECT', name: 'Ana Reyes', active: true, credit };
const TOKEN = 'a'.repeat(64);

let saleCalls;
let grantCalls;
let saleResults; // queue of answers to POST /sales
let grantResult;
let approversResult;

const limitRefusal = () => ({
    ok: false,
    status: 409,
    body: { error: { code: 'CREDIT_LIMIT_EXCEEDED', message: 'over', details: { employee_code: 'TSC00012', limit: '1500.00', used: '1490.00', remaining: '10.00', requested: '15.00', period_start: '2026-10-16', period_end: '2026-10-31' } } },
});
const saleOk = () => ({
    ok: true,
    status: 201,
    body: {
        id: 'sale1', transaction_number: 'T-ULID', document_mode: 'SLIP', document_reference: 'T-01-000001', grand_total: '15.00', amount_tendered: '15.00', change: '0.00',
        items: [{ id: 'i1', quantity: '1.000', product_name_snapshot: 'Plain rice', net_line_amount: '15.00' }], slip: { id: 'slip1' }, employee: { id: 'e1', employee_code: 'TSC00012', name: 'Ana Reyes' },
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
            const data = [{ id: 'rice', sku: 'ALC-RICE', name: 'Plain rice', selling_price: '15.00', category_id: null, active: true, unit_of_measure: 'order' }];
            return { ok: true, status: 200, body: { data, meta: { total: 1, last_page: 1 } } };
        }
        if (url.startsWith('/api/v1/employees/lookup')) {
            return { ok: true, status: 200, body: ana };
        }
        if (url.endsWith('/credit-status')) {
            return { ok: true, status: 200, body: ana };
        }
        if (url === '/api/v1/credit-override-approvers') {
            return approversResult();
        }
        if (url === '/api/v1/credit-overrides' && method === 'POST') {
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
    saleCalls = [];
    grantCalls = [];
    saleResults = [limitRefusal];
    approversResult = () => ({ ok: true, status: 200, body: { data: [{ id: 'm1', name: 'Mia Manager' }, { id: 'm2', name: 'Alan Admin' }] } });
    grantResult = () => ({ ok: true, status: 201, body: { grant_id: 'g1', token: TOKEN, expires_at: '2026-10-26T10:05:00+08:00', max_amount: '15.00', employee_id: 'e1', approver: { id: 'm1', name: 'Mia Manager' } } });
});

afterEach(cleanup);

/** Rings one rice on credit and presses Charge, so the refusal panel is showing. */
async function refused() {
    render(
        <MemoryRouter initialEntries={['/pos']}>
            <Pos />
        </MemoryRouter>,
    );
    fireEvent.click(await screen.findByRole('button', { name: /Credit/ }));
    fireEvent.change(await screen.findByLabelText('Employee code'), { target: { value: 'TSC00012' } });
    fireEvent.click(screen.getByRole('button', { name: 'Find' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
    fireEvent.click((await screen.findAllByText('Plain rice'))[0]);
    fireEvent.click(screen.getByRole('button', { name: /^Charge ₱15\.00 to Ana Reyes/ }));
    fireEvent.click(within(await screen.findByLabelText('Charge to employee')).getByRole('button', { name: 'Charge to Ana Reyes' }));
    await screen.findByLabelText('Credit limit exceeded');
}

const openApproval = async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Ask a manager to approve' }));
    return screen.findByRole('group', { name: 'Manager approval' });
};
const type = (panel, digits) => [...digits].forEach((digit) => fireEvent.click(within(within(panel).getByRole('group', { name: 'Code keypad' })).getByRole('button', { name: digit })));

describe('asking a manager to approve an over-limit credit sale', () => {
    it('lists the managers by name, takes the code on a masked keypad, and sends the sale again with the single-use approval', async () => {
        await refused();
        const panel = await openApproval();

        expect(await within(panel).findByRole('button', { name: 'Mia Manager' })).toBeTruthy();
        expect(within(panel).getByRole('button', { name: 'Alan Admin' })).toBeTruthy();
        fireEvent.click(within(panel).getByRole('button', { name: 'Mia Manager' }));
        type(panel, '482916');

        const box = within(panel).getByLabelText("Manager's code");
        expect(box.getAttribute('type')).toBe('password'); // masked: the cashier never reads it
        expect(box.getAttribute('inputmode')).toBe('numeric');
        expect(box.value).toBe('482916');
        expect(panel.textContent).not.toContain('482916');

        fireEvent.click(within(panel).getByRole('button', { name: 'Approve this sale' }));

        expect(await screen.findByText('Sale complete')).toBeTruthy();
        expect(grantCalls).toEqual([{ approver_id: 'm1', code: '482916', employee_id: 'e1', amount: '15.00' }]);
        expect(saleCalls).toHaveLength(2);
        expect(saleCalls[0].body.credit_override_token).toBeUndefined();
        expect(saleCalls[1].body).toEqual({
            items: [{ product_id: 'rice', quantity: '1' }],
            payments: [{ method: 'CREDIT', amount: '15.00' }],
            employee_id: 'e1',
            credit_override_token: TOKEN,
        });
        expect(saleCalls[1].key).not.toBe(saleCalls[0].key); // a different request, never a replay of the refused one
        expect(document.body.textContent).not.toContain(TOKEN);
        expect(document.body.textContent).not.toContain('482916');
    });

    it('Enter in the code box approves, and does not also submit the sale form around the panel', async () => {
        await refused();
        const panel = await openApproval();
        fireEvent.click(await within(panel).findByRole('button', { name: 'Alan Admin' }));
        const box = within(panel).getByLabelText("Manager's code");
        fireEvent.change(box, { target: { value: '739152' } });
        fireEvent.keyDown(box, { key: 'Enter' });

        expect(await screen.findByText('Sale complete')).toBeTruthy();
        expect(grantCalls).toEqual([{ approver_id: 'm2', code: '739152', employee_id: 'e1', amount: '15.00' }]);
        expect(saleCalls).toHaveLength(2); // the refused one and the approved one: no third, no duplicate
    });

    it('a wrong code says only that it did not work, clears the code, keeps the panel open and does not retry the sale', async () => {
        grantResult = () => ({ ok: false, status: 422, body: { error: { code: 'CREDIT_OVERRIDE_INVALID', message: 'That code did not work.' } } });
        await refused();
        const panel = await openApproval();
        fireEvent.click(await within(panel).findByRole('button', { name: 'Mia Manager' }));
        type(panel, '111222');
        fireEvent.click(within(panel).getByRole('button', { name: 'Approve this sale' }));

        expect((await within(panel).findByRole('alert')).textContent).toBe('That code did not work.');
        expect(within(panel).getByLabelText("Manager's code").value).toBe('');
        expect(panel.textContent).not.toMatch(/attempt|tries left|remaining/i);
        expect(saleCalls).toHaveLength(1);
        expect(screen.queryByText('Sale complete')).toBeNull();
        expect(within(panel).getByRole('button', { name: 'Approve this sale' }).disabled).toBe(true); // nothing typed
    });

    it('a locked manager shows the lock message and suggests another manager', async () => {
        grantResult = () => ({ ok: false, status: 423, body: { error: { code: 'CREDIT_OVERRIDE_LOCKED', message: 'Too many wrong codes. This approver is locked for 15 minutes. Ask another manager, or wait.' } } });
        await refused();
        const panel = await openApproval();
        fireEvent.click(await within(panel).findByRole('button', { name: 'Mia Manager' }));
        type(panel, '111222');
        fireEvent.click(within(panel).getByRole('button', { name: 'Approve this sale' }));

        expect((await within(panel).findByRole('alert')).textContent).toMatch(/locked for 15 minutes.*another manager/);
        expect(saleCalls).toHaveLength(1);
        // Another manager can be picked straight away.
        fireEvent.click(within(panel).getByRole('button', { name: 'Alan Admin' }));
        expect(within(panel).getByLabelText("Manager's code").value).toBe('');
    });

    it('Cancel closes the panel without asking the server anything, and the code is gone if it is opened again', async () => {
        await refused();
        let panel = await openApproval();
        fireEvent.click(await within(panel).findByRole('button', { name: 'Mia Manager' }));
        type(panel, '482');
        fireEvent.click(within(panel).getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('group', { name: 'Manager approval' })).toBeNull();
        expect(grantCalls).toEqual([]);
        expect(saleCalls).toHaveLength(1);

        panel = await openApproval();
        await within(panel).findByRole('button', { name: 'Mia Manager' });
        expect(within(panel).getByLabelText("Manager's code").value).toBe('');
    });

    it('needs a manager chosen and at least six digits before it will send anything, and keeps digits only', async () => {
        await refused();
        const panel = await openApproval();
        await within(panel).findByRole('button', { name: 'Mia Manager' });
        const submit = within(panel).getByRole('button', { name: 'Approve this sale' });

        expect(within(panel).getByLabelText("Manager's code").disabled).toBe(true); // pick a name first
        fireEvent.click(within(panel).getByRole('button', { name: 'Mia Manager' }));
        fireEvent.change(within(panel).getByLabelText("Manager's code"), { target: { value: '12ab34' } });
        expect(within(panel).getByLabelText("Manager's code").value).toBe('1234');
        expect(submit.disabled).toBe(true);
        type(panel, '56');
        expect(submit.disabled).toBe(false);
        type(panel, '789');
        expect(within(panel).getByLabelText("Manager's code").value).toBe('12345678'); // eight at most
    });

    it('says so when no manager has set a code yet', async () => {
        approversResult = () => ({ ok: true, status: 200, body: { data: [] } });
        await refused();
        const panel = await openApproval();

        expect((await within(panel).findByRole('status')).textContent).toMatch(/No manager has set an override code/);
        expect(within(panel).queryByLabelText("Manager's code")).toBeNull();
    });

    it('a refused approval is dropped: the next press of Charge goes without it and meets the limit again', async () => {
        saleResults = [limitRefusal, () => ({ ok: false, status: 409, body: { error: { code: 'CREDIT_OVERRIDE_EXPIRED', message: 'This approval has expired or was already used. Ask a manager to approve again.' } } }), limitRefusal];
        await refused();
        const panel = await openApproval();
        fireEvent.click(await within(panel).findByRole('button', { name: 'Mia Manager' }));
        type(panel, '482916');
        fireEvent.click(within(panel).getByRole('button', { name: 'Approve this sale' }));

        await waitFor(() => expect(screen.getByText(/has expired or was already used/)).toBeTruthy());
        expect(saleCalls[1].body.credit_override_token).toBe(TOKEN);

        fireEvent.click(within(screen.getByLabelText('Charge to employee')).getByRole('button', { name: 'Charge to Ana Reyes' }));
        await waitFor(() => expect(saleCalls).toHaveLength(3));
        expect(saleCalls[2].body.credit_override_token).toBeUndefined();
    });
});

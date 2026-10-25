import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// ADR-016 slice 3.2: the start-of-sale screen and the credit flow of the till, driven through the real Pos component with the API mocked.

const today = new Date().toISOString().slice(0, 10);
const credit = (remaining, used = '0.00') => ({ limit: '1500.00', used, remaining, period_start: '2026-10-16', period_end: '2026-10-31' });
const lookupBody = (overrides = {}) => ({ id: 'e1', employee_code: 'TSC00012', employee_type: 'DIRECT', name: 'Ana Reyes', active: true, credit: credit('1500.00'), ...overrides });

let employees;
let saleCalls;
let saleResponse;
let lookups;

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
                { id: 'soda', sku: 'BEV-SODA', name: 'Soda', selling_price: '20.00', category_id: null, active: true, unit_of_measure: 'can' },
            ];
            return { ok: true, status: 200, body: { data, meta: { total: 2, last_page: 1 } } };
        }
        if (url.startsWith('/api/v1/employees/lookup')) {
            const code = decodeURIComponent(url.split('code=')[1]);
            lookups.push(code);
            const found = employees[code.toUpperCase()];
            if (found === undefined) {
                return { ok: false, status: 404, body: { error: { code: 'EMPLOYEE_NOT_FOUND', message: 'No employee has this code.' } } };
            }
            if (found.active === false) {
                return { ok: false, status: 409, body: { error: { code: 'EMPLOYEE_INACTIVE', message: 'inactive' } } };
            }
            return { ok: true, status: 200, body: found };
        }
        if (url.endsWith('/credit-status')) {
            const id = url.split('/')[4];
            const found = Object.values(employees).find((employee) => employee.id === id);
            return { ok: true, status: 200, body: found };
        }
        if (url === '/api/v1/sales' && method === 'POST') {
            saleCalls.push({ body: options.body, key: options.headers['Idempotency-Key'] });
            return saleResponse();
        }
        return { ok: true, status: 200, body: { data: [] } };
    }),
}));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 'u1', name: 'Cora Cashier', capabilities: [] }, setUser: () => {} }) }));

const { default: Pos } = await import('./Pos');

beforeEach(() => {
    employees = { TSC00012: lookupBody(), SUB00007: lookupBody({ id: 'e2', employee_code: 'SUB00007', employee_type: 'SUBCONTRACTOR', name: 'Ben Cruz', credit: { ...credit('1000.00'), limit: '1000.00' } }), TSC00099: lookupBody({ id: 'e3', employee_code: 'TSC00099', name: 'Gone Away', active: false }) };
    saleCalls = [];
    lookups = [];
    saleResponse = () => ({
        ok: true,
        status: 201,
        body: {
            id: 'sale1', transaction_number: 'T-ULID', document_mode: 'SLIP', document_reference: 'T-01-000001', grand_total: '15.00', amount_tendered: '15.00', change: '0.00',
            items: [{ id: 'i1', quantity: '1.000', product_name_snapshot: 'Plain rice', net_line_amount: '15.00' }], slip: { id: 'slip1' },
            employee: { id: 'e1', employee_code: 'TSC00012', name: 'Ana Reyes' },
        },
    });
});

afterEach(cleanup);

const till = () =>
    render(
        <MemoryRouter initialEntries={['/pos']}>
            <Pos />
        </MemoryRouter>,
    );

const start = async () => {
    till();
    return screen.findByRole('button', { name: /^Cash/ });
};

async function enterCredit(code = 'TSC00012') {
    fireEvent.click(await screen.findByRole('button', { name: /Credit/ }));
    fireEvent.change(await screen.findByLabelText('Employee code'), { target: { value: code } });
    fireEvent.click(screen.getByRole('button', { name: 'Find' }));
}

// The catalogue tile comes first in the page; once the item is in the cart its name appears there too.
const addRice = async () => fireEvent.click((await screen.findAllByText('Plain rice'))[0]);

describe('start of sale', () => {
    it('opens on two big buttons, Cash and Credit, with Cash holding the focus so Enter is one action', async () => {
        const cash = await start();

        expect(screen.getByText('Who is paying?')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Credit/ })).toBeTruthy();
        await waitFor(() => expect(document.activeElement).toBe(cash));
        expect(screen.queryByText('Plain rice')).toBeNull(); // the register is not open yet
    });

    it('Cash opens the register at once, as before, and the cash checkout is the unchanged payment step with no employee', async () => {
        fireEvent.click(await start());

        expect(await screen.findByText('Plain rice')).toBeTruthy();
        expect(screen.getByLabelText('Payer').textContent).toContain('Cash');
        await addRice();
        fireEvent.click(screen.getByRole('button', { name: /^Charge ₱15\.00$/ }));

        // The ordinary tender step (method buttons, keypad), not the credit confirmation.
        expect(await screen.findByLabelText('Order summary')).toBeTruthy();
        expect(screen.queryByLabelText('Charge to employee')).toBeNull();
        expect(screen.getByRole('radio', { name: 'GCash' })).toBeTruthy();
        expect(screen.queryByRole('radio', { name: 'Credit' })).toBeNull(); // credit is chosen on the first screen, never as a tender
    });

    it('Credit looks the employee up and shows name, type, limit, used and remaining before Continue', async () => {
        till();
        await enterCredit(' tsc00012\n');

        const found = await screen.findByLabelText('Employee found');
        expect(lookups).toEqual(['tsc00012']); // trimmed; the server ignores case
        expect(found.textContent).toContain('Ana Reyes');
        expect(found.textContent).toContain('TSC00012');
        expect(found.textContent).toContain('₱1,500.00');
        expect(found.textContent).toContain('Oct 16 – Oct 31');
        expect(document.activeElement).toBe(within(found).getByRole('button', { name: 'Continue' }));

        fireEvent.click(within(found).getByRole('button', { name: 'Continue' }));
        expect(await screen.findByText('Plain rice')).toBeTruthy();
        expect(screen.getByLabelText('Payer').textContent).toContain('Ana Reyes');
        expect(screen.getByLabelText('Payer').textContent).toContain('₱1,500.00');
    });

    it('an Enter in the box submits the lookup (what a scanner sends after the code)', async () => {
        till();
        fireEvent.click(await screen.findByRole('button', { name: /Credit/ }));
        const box = await screen.findByLabelText('Employee code');
        expect(document.activeElement).toBe(box);
        fireEvent.change(box, { target: { value: 'SUB00007' } });
        fireEvent.submit(box.closest('form'));

        expect((await screen.findByLabelText('Employee found')).textContent).toContain('Ben Cruz');
        expect(screen.getByLabelText('Employee found').textContent).toContain('SUB');
        expect(screen.getByLabelText('Employee found').textContent).toContain('₱1,000.00');
    });

    it('characters typed on the start screen (a scanner with nothing focused) open the credit box with them', async () => {
        till();
        const cash = await screen.findByRole('button', { name: /Cash/ });
        fireEvent.keyDown(cash, { key: 'T' });

        const box = await screen.findByLabelText('Employee code');
        expect(box.value).toBe('T');
        // Cash and Credit stay on screen next to the box.
        expect(screen.getByRole('button', { name: /^Cash/ })).toBeTruthy();
    });

    it('says clearly when the code is unknown or the employee is inactive, and stays on the box', async () => {
        till();
        await enterCredit('TSC99999');
        expect((await screen.findByRole('alert')).textContent).toMatch(/No employee has this code/);
        expect(screen.queryByText('Plain rice')).toBeNull();

        fireEvent.change(screen.getByLabelText('Employee code'), { target: { value: 'TSC00099' } });
        fireEvent.click(screen.getByRole('button', { name: 'Find' }));
        await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/inactive/));
        expect(screen.queryByLabelText('Employee found')).toBeNull();
    });
});

describe('ringing a credit sale', () => {
    it('shows what is left now and after the cart, and warns when the cart is over the limit', async () => {
        employees.TSC00012 = lookupBody({ credit: credit('20.00', '1480.00') });
        till();
        await enterCredit();
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
        await addRice();

        const balance = screen.getByLabelText('Credit balance');
        expect(balance.textContent).toContain('₱20.00');
        expect(balance.textContent).toContain('₱5.00'); // 20.00 - 15.00
        expect(within(balance).queryByRole('alert')).toBeNull();
        expect(screen.getByRole('button', { name: 'Charge ₱15.00 to Ana Reyes' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'One more Plain rice' }));
        expect(within(screen.getByLabelText('Credit balance')).getByRole('alert').textContent).toMatch(/over Ana Reyes.s credit limit/);
        expect(screen.getByLabelText('Credit balance').textContent).toContain('-₱10.00');
    });

    it('sends one CREDIT payment for the total and the employee id, then shows who was charged on the receipt', async () => {
        till();
        await enterCredit();
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
        await addRice();
        fireEvent.click(screen.getByRole('button', { name: 'Charge ₱15.00 to Ana Reyes' }));

        // No tender step: a single confirmation naming the employee.
        const confirm = await screen.findByLabelText('Charge to employee');
        expect(within(confirm).queryByRole('radio')).toBeNull();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Charge to Ana Reyes' }));

        expect(await screen.findByText('Sale complete')).toBeTruthy();
        expect(saleCalls).toHaveLength(1);
        expect(saleCalls[0].body).toEqual({
            items: [{ product_id: 'rice', quantity: '1' }],
            payments: [{ method: 'CREDIT', amount: '15.00' }],
            employee_id: 'e1',
        });
        expect(screen.getByText('Charged to').closest('div').textContent).toContain('Ana Reyes');
        expect(screen.queryByText('Tendered')).toBeNull();
        expect(screen.queryByText('Change')).toBeNull();

        // New sale returns to the first screen; the previous payer is not carried over.
        fireEvent.click(screen.getByRole('button', { name: 'New sale' }));
        expect(await screen.findByText('Who is paying?')).toBeTruthy();
        expect(screen.queryByLabelText('Payer')).toBeNull();
    });

    it('shows the limit, used, remaining and the sale amount when the server refuses, offers a manager approval, and lets the cashier take cash', async () => {
        saleResponse = () => ({
            ok: false,
            status: 409,
            body: { error: { code: 'CREDIT_LIMIT_EXCEEDED', message: 'over', details: { employee_code: 'TSC00012', limit: '1500.00', used: '1490.00', remaining: '10.00', requested: '15.00', period_start: '2026-10-16', period_end: '2026-10-31' } } },
        });
        till();
        await enterCredit();
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
        await addRice();
        fireEvent.click(screen.getByRole('button', { name: 'Charge ₱15.00 to Ana Reyes' }));
        fireEvent.click(within(await screen.findByLabelText('Charge to employee')).getByRole('button', { name: 'Charge to Ana Reyes' }));

        const refusal = await screen.findByLabelText('Credit limit exceeded');
        expect(refusal.textContent).toContain('₱1,490.00');
        expect(refusal.textContent).toContain('₱10.00');
        expect(refusal.textContent).toContain('₱15.00');
        expect(within(refusal).getByRole('button', { name: 'Ask a manager to approve' })).toBeTruthy(); // slice 3.3
        expect(screen.queryByText('Sale complete')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Pay with cash instead' }));
        expect(await screen.findByLabelText('Cart')).toBeTruthy();
        expect(screen.getByLabelText('Payer').textContent).toContain('Cash');
        expect(within(screen.getByLabelText('Cart')).getByText('Plain rice')).toBeTruthy(); // nothing was lost
    });
});

describe('changing who pays', () => {
    it('switches to cash and to another employee in the middle of an order without losing the cart', async () => {
        till();
        await enterCredit();
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
        await addRice();
        await addRice();

        fireEvent.click(screen.getByRole('button', { name: 'Pay with cash instead' }));
        expect(screen.getByLabelText('Payer').textContent).toContain('Cash');
        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('2');

        fireEvent.click(screen.getByRole('button', { name: 'Charge to employee' }));
        const dialog = await screen.findByRole('dialog', { name: 'Charge to employee' });
        fireEvent.change(within(dialog).getByLabelText('Employee code'), { target: { value: 'SUB00007' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Find' }));
        fireEvent.click(await within(dialog).findByRole('button', { name: 'Continue' }));

        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Charge to employee' })).toBeNull());
        expect(screen.getByLabelText('Payer').textContent).toContain('Ben Cruz');
        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('2');
        expect(screen.getByRole('button', { name: 'Charge ₱30.00 to Ben Cruz' })).toBeTruthy();
    });

    it('Start over goes straight back to the first screen with an empty cart, and asks first when there are items', async () => {
        till();
        await enterCredit();
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: 'Start over' }));
        expect(await screen.findByText('Who is paying?')).toBeTruthy();

        fireEvent.click(await screen.findByRole('button', { name: /Cash/ }));
        await addRice();
        fireEvent.click(screen.getByRole('button', { name: 'Start over' }));

        const dialog = await screen.findByRole('alertdialog');
        expect(dialog.textContent).toMatch(/One item is in the cart/);
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText('Quantity of Plain rice').value).toBe('1');

        fireEvent.click(screen.getByRole('button', { name: 'Start over' }));
        fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Clear the cart and start over' }));
        expect(await screen.findByText('Who is paying?')).toBeTruthy();
        expect(screen.queryByLabelText('Cart')).toBeNull();
    });
});

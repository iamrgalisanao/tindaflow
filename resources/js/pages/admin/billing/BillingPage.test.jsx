import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BillingPage from './BillingPage';
import { currentCutoff } from './billingConfig';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['BILLING_MANAGE', 'EMPLOYEE_MANAGE'] } }) }));
const printSpy = vi.fn();
vi.mock('../../../lib/usePrintFrame', () => ({ usePrintFrame: () => ({ print: printSpy, frame: null }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

const item = (overrides) => ({ item: 'Beef nilaga', kind: 'DISH', quantity_sold: '1.000', sold_amount: '68.00', quantity_returned: '0.000', returned_amount: '0.00', quantity_net: '1.000', net_amount: '68.00', ...overrides });

function statement(overrides = {}) {
    const { month, half } = currentCutoff();
    return {
        version: 1,
        party: { id: 'p', name: 'TSC' },
        criteria: { from: `${month}-01`, to: `${month}-15`, period_label: 'Oct 1-15, 2026', cutoff_month: month, cutoff_half: Number(half), employee_type: null, employee_id: null, employee_code: null, as_of: '2026-10-26T10:00:00+08:00' },
        generated_at: '2026-10-26T10:00:00+08:00',
        accounts: [
            {
                employee_id: 'e1', employee_code: 'TSC00001', employee_name: 'Ana Reyes', employee_type: 'DIRECT', sale_count: 2, sold_amount: '183.00', returned_amount: '100.00', net_amount: '83.00',
                meal_types: [
                    { meal_type: 'Standard Meal', sold_amount: '83.00', returned_amount: '0.00', net_amount: '83.00', items: [item(), item({ item: 'Whole rice', kind: 'ADDON', sold_amount: '15.00', net_amount: '15.00' })] },
                    { meal_type: 'Other items', sold_amount: '100.00', returned_amount: '100.00', net_amount: '0.00', items: [item({ item: 'Bottled water', kind: 'PRODUCT', sold_amount: '100.00', returned_amount: '100.00', quantity_returned: '1.000', net_amount: '0.00' })] },
                ],
            },
            { employee_id: 'e2', employee_code: 'SUB00001', employee_name: 'Ben Cruz', employee_type: 'SUBCONTRACTOR', sale_count: 1, sold_amount: '50.00', returned_amount: '0.00', net_amount: '50.00', meal_types: [{ meal_type: 'Snacks', sold_amount: '50.00', returned_amount: '0.00', net_amount: '50.00', items: [item({ item: 'Banana cue', kind: 'PRODUCT', sold_amount: '50.00', net_amount: '50.00' })] }] },
        ],
        adjustments: [{ entry_id: 'r1', type: 'REFUND', recorded_at: '2026-10-20T10:00:00+08:00', sale_id: 's', document_reference: '000123', sold_on: '2026-10-05', employee_id: 'e1', employee_code: 'TSC00001', employee_name: 'Ana Reyes', employee_type: 'DIRECT', amount: '100.00', reason: 'Returned', lines: [{ meal_type: 'Other items', item: 'Bottled water', kind: 'PRODUCT', quantity: '1.000', amount: '100.00' }] }],
        totals: { sold_amount: '233.00', returned_amount: '100.00', net_amount: '133.00', employee_count: 2, sale_count: 3, adjustment_count: 1, by_employee_type: { DIRECT: { employee_count: 1, net_amount: '83.00' }, SUBCONTRACTOR: { employee_count: 1, net_amount: '50.00' } } },
        reconciliation: { ledger_net: '133.00', statement_net: '133.00', items_net: '133.00', matches: true },
        comparison: null,
        ...overrides,
    };
}

let calls;
let current;
beforeEach(() => {
    printSpy.mockClear();
    calls = [];
    current = statement();
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            const method = options.method ?? 'GET';
            calls.push({ url, method, headers: options.headers, body: options.body ? JSON.parse(options.body) : undefined });
            if (url.startsWith('/api/v1/employees')) {
                return json({ data: [{ id: 'e1', employee_code: 'TSC00001', name: 'Ana Reyes' }, { id: 'e2', employee_code: 'SUB00001', name: 'Ben Cruz' }], meta: { page: 1, per_page: 100, total: 2, last_page: 1 } });
            }
            if (url === '/api/v1/billing-runs' && method === 'POST') {
                return json({ data: { id: 'run-1', run_number: 'BR-000001', statement: current, statement_intact: true } }, 201);
            }
            if (url.startsWith('/api/v1/billing/statement') && options.headers?.Accept === 'text/csv') {
                return Promise.resolve(new Response('row_type\nGRAND_TOTAL\n', { status: 200, headers: { 'Content-Type': 'text/csv' } }));
            }
            if (url.startsWith('/api/v1/billing/statement')) {
                return json({ data: current });
            }
            return json({}, 404);
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const page = () =>
    render(
        <MemoryRouter>
            <BillingPage />
        </MemoryRouter>,
    );
const statementCalls = () => calls.filter((call) => call.url.startsWith('/api/v1/billing/statement') && call.headers?.Accept !== 'text/csv');

async function generate() {
    fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
    await screen.findByText('Grand total');
}

describe('BillingPage', () => {
    it('asks for the current cut-off and shows the totals, the ledger match and every account collapsed with its subtotal', async () => {
        page();
        await generate();

        const { month, half } = currentCutoff();
        expect(statementCalls()[0].url).toBe(`/api/v1/billing/statement?cutoff_month=${month}&cutoff_half=${half}`);
        const totals = screen.getByRole('region', { name: 'Statement totals' });
        expect(within(totals).getByText('₱133.00')).toBeTruthy();
        expect(within(totals).getByText('-₱100.00')).toBeTruthy();
        expect(within(totals).getByText(/Matches the credit ledger \(₱133\.00\)/)).toBeTruthy();
        expect(within(totals).getByText(/TSC: 1 employees, ₱83\.00 · SUB: 1 employees, ₱50\.00/)).toBeTruthy();

        const accounts = screen.getByRole('region', { name: 'Accounts' });
        expect(within(accounts).getByText('TSC00001')).toBeTruthy();
        expect(within(accounts).getByText('SUB00001')).toBeTruthy();
        expect(within(accounts).getAllByRole('button', { expanded: false })).toHaveLength(2);
        expect(within(accounts).queryByText('Standard Meal')).toBeNull();
        expect(within(accounts).getByText('Grand total').parentElement.textContent).toContain('₱133.00');
    });

    it('opens one employee to meal type and item, and Expand all / Collapse all open and close everybody', async () => {
        page();
        await generate();
        const accounts = screen.getByRole('region', { name: 'Accounts' });

        fireEvent.click(within(accounts).getByText('Ana Reyes'));
        expect(within(accounts).getByText('Standard Meal')).toBeTruthy();
        expect(within(accounts).getByText('Beef nilaga')).toBeTruthy();
        expect(within(accounts).getByText('Whole rice')).toBeTruthy();
        expect(within(accounts).getByText('Other items')).toBeTruthy();
        expect(within(accounts).queryByText('Banana cue')).toBeNull();

        fireEvent.click(within(accounts).getByRole('button', { name: 'Expand all' }));
        expect(within(accounts).getByText('Banana cue')).toBeTruthy();
        fireEvent.click(within(accounts).getByRole('button', { name: 'Collapse all' }));
        expect(within(accounts).queryByText('Beef nilaga')).toBeNull();
    });

    it('lists every void and refund in its own Adjustments section as a deduction', async () => {
        page();
        await generate();
        const adjustments = screen.getByRole('region', { name: 'Adjustments' });

        expect(within(adjustments).getByText('REFUND')).toBeTruthy();
        expect(within(adjustments).getByText('000123')).toBeTruthy();
        expect(within(adjustments).getAllByText('-₱100.00').length).toBeGreaterThan(0);
        expect(within(adjustments).getByText('2026-10-05')).toBeTruthy();
        expect(within(adjustments).getByText(/Bottled water/)).toBeTruthy();
    });

    it('sends the chosen filters, custom dates and an as-of time, and refuses a bad range without calling the server', async () => {
        page();
        fireEvent.click(screen.getByLabelText('Any dates'));
        fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-20' } });
        fireEvent.change(screen.getByLabelText('To (included)'), { target: { value: '2026-10-01' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
        expect(await screen.findByText('The first date cannot be after the last date.')).toBeTruthy();
        expect(statementCalls()).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-05' } });
        fireEvent.change(screen.getByLabelText('To (included)'), { target: { value: '2026-10-20' } });
        fireEvent.change(screen.getByLabelText('Employee type'), { target: { value: 'SUBCONTRACTOR' } });
        await waitFor(() => expect(calls.some((call) => call.url.startsWith('/api/v1/employees'))).toBe(true));
        fireEvent.change(screen.getByLabelText('As of (optional, Manila time)'), { target: { value: '2026-10-26T14:30' } });
        await generate();

        expect(statementCalls()[0].url).toBe('/api/v1/billing/statement?from=2026-10-05&to=2026-10-20&employee_type=SUBCONTRACTOR&as_of=2026-10-26T14%3A30%3A00%2B08%3A00');
    });

    it('picks one employee from the list by code and name', async () => {
        page();
        await waitFor(() => expect(document.querySelectorAll('#billing-employee-options option')).toHaveLength(2));
        fireEvent.change(screen.getByLabelText('One employee (optional)'), { target: { value: 'SUB00001 - Ben Cruz' } });
        await generate();
        expect(statementCalls()[0].url).toContain('employee_id=e2');

        fireEvent.change(screen.getByLabelText('One employee (optional)'), { target: { value: 'Nobody' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
        expect(await screen.findByText('Pick an employee from the list, or clear the box.')).toBeTruthy();
    });

    it('saves a billing run once, shows its number and keeps the printed copy', async () => {
        page();
        await generate();
        expect(screen.getByText(/Not saved yet/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save as billing run' }));
        expect(await screen.findAllByText(/BR-000001/)).not.toHaveLength(0);
        const post = calls.find((call) => call.method === 'POST');
        const { month, half } = currentCutoff();
        expect(post.body).toEqual({ cutoff_month: month, cutoff_half: half });
        expect(screen.getByRole('button', { name: 'Save as billing run' }).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Print' }));
        expect(printSpy).toHaveBeenCalledTimes(1);
        expect(printSpy.mock.calls[0][0]).toContain('BR-000001');
    });

    it('prints the statement and exports it as CSV', async () => {
        page();
        await generate();

        fireEvent.click(screen.getByRole('button', { name: 'Print' }));
        expect(printSpy).toHaveBeenCalledTimes(1);
        expect(printSpy.mock.calls[0][0]).toContain('TOTAL DUE ₱133.00');
        expect(printSpy.mock.calls[0][0]).toContain('Not saved');

        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalled());
        const csv = calls.find((call) => call.headers?.Accept === 'text/csv');
        expect(csv.url).toContain('/api/v1/billing/statement?cutoff_month=');
        expect(await screen.findByText(/Exported billing_/)).toBeTruthy();
    });

    it('says when the period changed since the last saved run, with the added adjustment', async () => {
        current = statement({
            comparison: {
                previous_run: { id: 'r0', run_number: 'BR-000004', generated_at: '2026-10-20T09:00:00+08:00', as_of: '2026-10-20T09:00:00+08:00', sold_amount: '233.00', returned_amount: '0.00', net_amount: '233.00' },
                sold_change: '0.00', returned_change: '100.00', net_change: '-100.00', changed: true,
                added_adjustments: [{ entry_id: 'r1', type: 'REFUND', recorded_at: '2026-10-20T10:00:00+08:00', employee_code: 'TSC00001', employee_name: 'Ana Reyes', amount: '100.00', sold_on: '2026-10-05' }],
                removed_adjustments: [],
            },
        });
        page();
        await generate();

        const banner = screen.getByRole('status', { name: 'Changes since the last saved run' });
        expect(within(banner).getByText(/changed since billing run BR-000004/)).toBeTruthy();
        expect(within(banner).getByText(/Total due was ₱233\.00, now/)).toBeTruthy();
        expect(within(banner).getByText(/Refund of TSC00001 Ana Reyes, -₱100\.00 \(sold 2026-10-05/)).toBeTruthy();
    });

    it('shows the server message when the statement is refused', async () => {
        fetch.mockImplementation((url) => (url.startsWith('/api/v1/employees') ? json({ data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } }) : json({ error: { code: 'VALIDATION_FAILED', message: 'x', details: { to: ['A statement can cover at most 366 days. Choose a shorter range.'] } } }, 422)));
        page();
        fireEvent.click(screen.getByRole('button', { name: 'Generate' }));
        expect(await screen.findByRole('alert')).toBeTruthy();
        expect(screen.getByRole('alert').textContent).toContain('at most 366 days');
    });
});

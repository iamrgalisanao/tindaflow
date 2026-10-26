import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BillingRunPage from './BillingRunPage';
import BillingRunsPage from './BillingRunsPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities: ['BILLING_MANAGE'] } }) }));
const printSpy = vi.fn();
vi.mock('../../../lib/usePrintFrame', () => ({ usePrintFrame: () => ({ print: printSpy, frame: null }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

const summary = { id: 'run-1', run_number: 'BR-000001', from: '2026-10-01', to: '2026-10-15', period_label: 'Oct 1-15, 2026', employee_type: null, employee_code: null, generated_at: '2026-10-20T09:00:00+08:00', generated_by: { id: 'u', name: 'Mia Manager' }, net_amount: '1200.00', employee_count: 12 };
const statement = {
    party: { id: 'p', name: 'TSC' },
    criteria: { from: '2026-10-01', to: '2026-10-15', period_label: 'Oct 1-15, 2026', as_of: '2026-10-20T09:00:00+08:00', employee_type: null, employee_code: null },
    generated_at: '2026-10-20T09:00:00+08:00',
    accounts: [], adjustments: [], comparison: null,
    totals: { sold_amount: '1200.00', returned_amount: '0.00', net_amount: '1200.00', employee_count: 12, sale_count: 30, adjustment_count: 0, by_employee_type: {} },
    reconciliation: { ledger_net: '1200.00', statement_net: '1200.00', items_net: '1200.00', matches: true },
};

let intact;
beforeEach(() => {
    printSpy.mockClear();
    intact = true;
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            if (options.headers?.Accept === 'text/csv') {
                return Promise.resolve(new Response('row_type\n', { status: 200 }));
            }
            if (url.startsWith('/api/v1/billing-runs/run-1')) {
                return json({ data: { ...summary, statement, statement_intact: intact } });
            }
            if (url.startsWith('/api/v1/billing-runs/missing')) {
                return json({ error: { code: 'BILLING_RUN_NOT_FOUND', message: 'nope', details: {} } }, 404);
            }
            return json({ data: [summary], meta: { page: 1, per_page: 25, total: 1, last_page: 1 } });
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('BillingRunsPage', () => {
    it('lists the saved runs with open, print and CSV, and reprints a run exactly as saved', async () => {
        render(
            <MemoryRouter>
                <BillingRunsPage />
            </MemoryRouter>,
        );
        expect(await screen.findByText('BR-000001')).toBeTruthy();
        expect(screen.getByText('Mia Manager')).toBeTruthy();
        expect(screen.getByText('₱1,200.00')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Open BR-000001' }).getAttribute('href')).toBe('/admin/billing/runs/run-1');

        fireEvent.click(screen.getByRole('button', { name: 'Print BR-000001' }));
        await waitFor(() => expect(printSpy).toHaveBeenCalledTimes(1));
        expect(printSpy.mock.calls[0][0]).toContain('BR-000001');

        fireEvent.click(screen.getByRole('button', { name: 'Export BR-000001 as CSV' }));
        await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalled());
    });

    it('says so when nothing has been saved', async () => {
        fetch.mockImplementation(() => json({ data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }));
        render(
            <MemoryRouter>
                <BillingRunsPage />
            </MemoryRouter>,
        );
        expect(await screen.findByText('No billing runs have been saved yet.')).toBeTruthy();
    });
});

describe('BillingRunPage', () => {
    const open = (id) =>
        render(
            <MemoryRouter initialEntries={[`/admin/billing/runs/${id}`]}>
                <Routes>
                    <Route path="/admin/billing/runs/:runId" element={<BillingRunPage />} />
                </Routes>
            </MemoryRouter>,
        );

    it('shows the frozen statement with print and CSV', async () => {
        open('run-1');
        expect(await screen.findByText('No credit sales in this period.')).toBeTruthy();
        expect(screen.getByText('BR-000001')).toBeTruthy();
        expect(screen.queryByText(/no longer matches the fingerprint/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Print' }));
        expect(printSpy.mock.calls[0][0]).toContain('BR-000001');
        expect(screen.getByRole('button', { name: 'Export CSV' })).toBeTruthy();
    });

    it('warns loudly when the saved statement no longer matches its fingerprint', async () => {
        intact = false;
        open('run-1');
        expect(await screen.findByText(/no longer matches the fingerprint/)).toBeTruthy();
    });

    it('says when the run does not exist', async () => {
        open('missing');
        expect(await screen.findByText('That billing run was not found.')).toBeTruthy();
    });
});

import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EmployeesPage from './EmployeesPage';

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
let capabilities;
vi.mock('../../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities } }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const employee = (overrides = {}) => ({
    id: 'e1', employee_code: 'TSC00001', employee_type: 'DIRECT', first_name: 'Ana', middle_name: null, last_name: 'Reyes', name: 'Ana Reyes', billing_party_id: 'p', active: true, ...overrides,
});

let calls;
let rows;
beforeEach(() => {
    capabilities = ['EMPLOYEE_MANAGE', 'EMPLOYEE_POLICY_MANAGE']; // an administrator unless a test says otherwise
    calls = [];
    rows = [employee(), employee({ id: 'e2', employee_code: 'SUB00001', employee_type: 'SUBCONTRACTOR', name: 'Ramon Garcia', first_name: 'Ramon', last_name: 'Garcia', active: false })];
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            const method = options.method ?? 'GET';
            calls.push({ url, method, body: options.body ? JSON.parse(options.body) : undefined });
            if (url.startsWith('/api/v1/credit-policies') && method === 'GET') {
                return json({ data: [{ employee_type: 'DIRECT', limit_per_cutoff: '1500.00' }, { employee_type: 'SUBCONTRACTOR', limit_per_cutoff: '1000.00' }] });
            }
            if (url.startsWith('/api/v1/credit-policies') && method === 'PUT') {
                return json({ employee_type: 'SUBCONTRACTOR', limit_per_cutoff: JSON.parse(options.body).limit_per_cutoff });
            }
            if (url === '/api/v1/employees' && method === 'POST') {
                return json(employee({ id: 'e3', employee_code: 'TSC00002', ...JSON.parse(options.body) }), 201);
            }
            return json({ data: rows, meta: { page: 1, per_page: 25, total: rows.length, last_page: 1 } });
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
            <EmployeesPage />
        </MemoryRouter>,
    );

describe('EmployeesPage', () => {
    it('lists employees with their generated codes and status', async () => {
        page();
        expect(await screen.findAllByText('TSC00001')).not.toHaveLength(0);
        expect(screen.getAllByText('SUB00001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('INACTIVE').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'Reactivate' }).length).toBeGreaterThan(0);
    });

    it('searches and filters on the server', async () => {
        page();
        await screen.findAllByText('TSC00001');

        fireEvent.change(screen.getByLabelText('Search by name or code'), { target: { value: 'ana' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        await waitFor(() => expect(calls.some((call) => call.url.includes('search=ana'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'SUBCONTRACTOR' } });
        await waitFor(() => expect(calls.some((call) => call.url.includes('employee_type=SUBCONTRACTOR'))).toBe(true));
    });

    it('creates an employee without ever sending a code and then shows the code the system made', async () => {
        page();
        await screen.findAllByText('TSC00001');

        fireEvent.click(screen.getByRole('button', { name: 'New employee' }));
        fireEvent.click(screen.getByLabelText('TSC (direct)'));
        fireEvent.change(screen.getByLabelText(/First name/), { target: { value: 'Ben' } });
        fireEvent.change(screen.getByLabelText(/Last name/), { target: { value: 'Cruz' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create employee' }));

        expect(await screen.findByText('Their code is:', { exact: false })).toBeTruthy();
        expect(screen.getAllByText('TSC00002').length).toBeGreaterThan(0);
        const post = calls.find((call) => call.method === 'POST' && call.url === '/api/v1/employees');
        expect(post.body).toEqual({ employee_type: 'DIRECT', first_name: 'Ben', middle_name: null, last_name: 'Cruz' });
        expect(Object.keys(post.body)).not.toContain('employee_code');
    });

    it('asks before deactivating', async () => {
        page();
        await screen.findAllByText('TSC00001');

        fireEvent.click(screen.getAllByRole('button', { name: 'Deactivate' })[0]);
        expect(screen.getByRole('alertdialog').textContent).toContain('TSC00001 will no longer be accepted');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(calls.some((call) => call.url.endsWith('/deactivate'))).toBe(false);
    });

    it('changes a credit limit as a normalised amount and refuses a bad one without calling the server', async () => {
        page();
        const input = await screen.findByLabelText(/SUB \(subcontractor\) .* limit per cut-off/);

        fireEvent.change(input, { target: { value: '₱1,250.5' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[1]);
        await waitFor(() => expect(calls.some((call) => call.method === 'PUT')).toBe(true));
        expect(calls.find((call) => call.method === 'PUT')).toMatchObject({ url: '/api/v1/credit-policies/SUBCONTRACTOR', body: { limit_per_cutoff: '1250.50' } });

        calls.length = 0;
        fireEvent.change(input, { target: { value: 'abc' } });
        expect(screen.getAllByRole('button', { name: 'Save' })[1].disabled).toBe(true);
        expect(calls.some((call) => call.method === 'PUT')).toBe(false);
    });

    it('shows a manager the list, the create and edit controls and the limits read-only, with no limit editing and no CSV import', async () => {
        capabilities = ['EMPLOYEE_MANAGE'];
        page();
        await screen.findAllByText('TSC00001');

        expect(screen.getByRole('button', { name: 'New employee' })).toBeTruthy();
        expect(screen.getAllByRole('button', { name: 'Edit' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'Deactivate' }).length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Print ID cards' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Import CSV' })).toBeNull();
        expect(await screen.findByText('₱1,500.00')).toBeTruthy();
        expect(screen.queryByLabelText(/limit per cut-off/)).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });

    it('shows an administrator the limit fields and the CSV import', async () => {
        page();
        await screen.findAllByText('TSC00001');

        expect(screen.getByRole('button', { name: 'Import CSV' })).toBeTruthy();
        expect(await screen.findByLabelText(/DIRECT|TSC \(direct\)/)).toBeTruthy();
    });
});

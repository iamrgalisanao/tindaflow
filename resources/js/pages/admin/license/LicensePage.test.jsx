import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminLayout from '../AdminLayout';
import LicenseNotice from './LicenseNotice';
import LicensePage from './LicensePage';
import { licenseNotice } from './licenseState';

let capabilities;
let role;
vi.mock('../../../context/AuthContext', () => ({
    useAuth: () => ({ logout: () => {}, setUser: () => {}, user: { name: 'Maria Santos', role, capabilities } }),
}));

// The capability sets the server reports for each role (RoleCapabilityCatalog).
const MANAGER = [
    'SALE_VOID', 'SALE_VOID_APPROVE', 'SALE_REFUND', 'SALE_REFUND_APPROVE', 'PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'STOCK_ADJUST', 'CASH_OUT',
    'REPORT_VIEW', 'CATALOG_MANAGE', 'AUDIT_VIEW', 'JOURNAL_VIEW', 'FISCAL_DAY_CLOSE', 'EMPLOYEE_MANAGE', 'CREDIT_LIMIT_OVERRIDE', 'BILLING_MANAGE',
    'STOCK_SALE_OVERRIDE', 'USER_MANAGE', 'LICENSE_MANAGE',
];
const CASHIER = ['SALE_VOID', 'SALE_REFUND'];

const active = {
    state: 'ACTIVE', enforcing: true, reason: null, message: null, deployment_code: '6A5D4E11', certificate_number: 42, seats: 1,
    valid_until: '2026-12-25T05:00:00+08:00', certificate_expires_at: '2026-12-25T05:00:00+08:00', extended_until: null,
    extensions_used: 0, extensions_allowed: 2, trusted_time: null, last_renewed_at: null, last_renewal_attempt_at: null,
    last_renewal_error: null, hardware_file: { status: 'FRESH', generated_at: null }, accepts_offline_codes: true,
};
const expired = { ...active, state: 'EXPIRED', reason: 'EXPIRED', message: 'The license has expired. Renew it, or ask your TindaFlow provider.' };

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

let calls;
let license;
let codeAnswer;
beforeEach(() => {
    role = 'MANAGER';
    capabilities = MANAGER;
    calls = [];
    license = active;
    codeAnswer = null;
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (url === '/api/v1/license/renew') {
                return json({ outcome: 'FAILED', message: 'The control plane could not be reached.', license: { ...license, last_renewal_error: 'The control plane could not be reached.' } });
            }
            if (url === '/api/v1/license/codes') {
                return codeAnswer();
            }
            return json(license);
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const show = (element) => render(<MemoryRouter initialEntries={['/admin/license']}>{element}</MemoryRouter>);

describe('LicensePage', () => {
    it('shows the state, how long it runs and the certificate number the provider asks for', async () => {
        show(<LicensePage />);

        const section = await screen.findByRole('region', { name: 'License state' });
        expect(within(section).getByText('Active')).toBeTruthy();
        expect(within(section).getByText('42')).toBeTruthy();
        expect(within(section).getByText('6A5D4E11')).toBeTruthy();
        expect(within(section).getByText('Up to date')).toBeTruthy();
    });

    it('says why new shifts are refused when the license has expired', async () => {
        license = expired;
        show(<LicensePage />);

        expect(await screen.findByText(expired.message)).toBeTruthy();
        expect(screen.getByText('Expired')).toBeTruthy();
    });

    it('says so, and offers nothing to do, in a build that is not license-managed', async () => {
        license = { ...active, state: 'LEGACY', enforcing: false, certificate_number: null, hardware_file: null, accepts_offline_codes: false };
        show(<LicensePage />);

        expect(await screen.findByText(/not license-managed/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Check license now' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Apply code' })).toBeNull();
    });

    it('checks now and reports a failed attempt as an alert with the reason', async () => {
        show(<LicensePage />);
        fireEvent.click(await screen.findByRole('button', { name: 'Check license now' }));

        expect((await screen.findByRole('alert')).textContent).toContain('could not be reached');
        expect(calls.find((call) => call.method === 'POST')).toMatchObject({ url: '/api/v1/license/renew' });
    });

    it('applies a code, shows what it did and clears the box', async () => {
        license = expired;
        codeAnswer = () => json({ message: 'Extended: the license now runs until 2027-01-26 05:00.', license: { ...active, extended_until: '2027-01-26T05:00:00+08:00', extensions_used: 1 } });
        show(<LicensePage />);
        const box = await screen.findByLabelText('Code from your provider');

        expect(screen.getByRole('button', { name: 'Apply code' }).disabled).toBe(true);
        fireEvent.change(box, { target: { value: 'ABCDE-FGHJK' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply code' }));

        expect((await screen.findByRole('status')).textContent).toContain('Extended');
        expect(box.value).toBe('');
        expect(screen.getByText(/1 of 2 in a row/)).toBeTruthy();
        expect(calls.find((call) => call.url === '/api/v1/license/codes')).toMatchObject({ method: 'POST', body: { code: 'ABCDE-FGHJK' } });
    });

    it('keeps a refused code in the box so it can be corrected', async () => {
        codeAnswer = () => json({ error: { code: 'LICENSE_CODE_REFUSED', message: 'The code is incomplete or mistyped. Nothing was changed.' } }, 422);
        show(<LicensePage />);
        const box = await screen.findByLabelText('Code from your provider');

        fireEvent.change(box, { target: { value: 'WRONG' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply code' }));

        expect((await screen.findByRole('alert')).textContent).toContain('incomplete or mistyped');
        expect(box.value).toBe('WRONG');
    });

    it('offers no code entry when the build accepts no codes', async () => {
        license = { ...active, accepts_offline_codes: false };
        show(<LicensePage />);
        await screen.findByRole('button', { name: 'Check license now' });

        expect(screen.queryByRole('button', { name: 'Apply code' })).toBeNull();
    });
});

describe('the Manager area navigation', () => {
    const links = () => within(screen.getAllByRole('navigation', { name: 'Back office' })[0]).getAllByRole('link').map((link) => link.getAttribute('title'));

    it('lists for a manager the screens ADR-018 names, and no administrator-only screen', () => {
        show(<AdminLayout requiredCapability={null}>page</AdminLayout>);

        for (const wanted of ['Users', 'Catalog', 'Meal menu', 'Employees', 'License']) {
            expect(links()).toContain(wanted);
        }
        expect(links()).not.toContain('Terminals');
        expect(links()).not.toContain('Store Setup');
    });

    it('does not offer the license screen to a cashier', () => {
        role = 'CASHIER';
        capabilities = CASHIER;
        show(<AdminLayout requiredCapability={null}>page</AdminLayout>);

        expect(links()).not.toContain('License');
        expect(links()).not.toContain('Users');
    });
});

describe('the dashboard license notice', () => {
    it('stays silent while all is well and in a build that is not license-managed', () => {
        expect(licenseNotice(active)).toBeNull();
        expect(licenseNotice({ state: 'LEGACY' })).toBeNull();
        expect(licenseNotice(null)).toBeNull();
    });

    it('warns before expiry, and says what is refused afterwards', () => {
        expect(licenseNotice({ ...active, state: 'WARNING' }).tone).toBe('amber');
        expect(licenseNotice(expired).text).toContain('New shifts cannot be opened.');
        expect(licenseNotice({ ...expired, enforcing: false }).text).toContain('nothing is refused');
    });

    it('asks the server only for someone who can act on it', async () => {
        license = expired;
        show(<LicenseNotice allowed={false} />);
        expect(calls).toHaveLength(0);
        cleanup();

        show(<LicenseNotice allowed />);
        await waitFor(() => expect(screen.getByRole('status').textContent).toContain('has expired'));
        expect(screen.getByRole('link', { name: 'Open License' }).getAttribute('href')).toBe('/admin/license');
    });
});

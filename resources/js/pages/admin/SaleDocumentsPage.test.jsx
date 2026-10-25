import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SaleDocumentsPage from './SaleDocumentsPage';

let capabilities;
vi.mock('./AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ setUser: () => {}, user: { capabilities } }) }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

let calls;
let settings;
beforeEach(() => {
    calls = [];
    capabilities = ['STORE_SETTINGS_MANAGE', 'FISCAL_CONFIGURATION_MANAGE'];
    settings = { sale_document_mode: 'INVOICE', slip_footer: null, open_shifts: 0, open_fiscal_days: 0 };
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined });
            if (options.method === 'PUT' && url.endsWith('/mode')) {
                settings = { ...settings, sale_document_mode: JSON.parse(options.body).sale_document_mode };
                return json(settings);
            }
            if (options.method === 'PUT') {
                settings = { ...settings, slip_footer: JSON.parse(options.body).slip_footer };
                return json(settings);
            }
            return json(settings);
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('SaleDocumentsPage', () => {
    it('shows the mode in use and, before switching, what the switch does', async () => {
        render(<SaleDocumentsPage />);
        expect(await screen.findByText('IN USE')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Switch to slip' }));
        expect(screen.getByText(/Sales already made keep the invoice they were issued/)).toBeTruthy();
        expect(screen.getByText(/shift report work exactly as before/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Yes, switch' }));
        await waitFor(() => expect(calls.some((call) => call.method === 'PUT')).toBe(true));
        expect(calls.find((call) => call.method === 'PUT')).toMatchObject({ url: '/api/v1/sale-document-settings/mode', body: { sale_document_mode: 'SLIP' } });
    });

    it('does not offer the switch while a shift or business day is open, and says why', async () => {
        settings = { ...settings, open_shifts: 1, open_fiscal_days: 1 };
        render(<SaleDocumentsPage />);
        await screen.findByText('IN USE');

        expect(screen.getByRole('button', { name: 'Switch to slip' }).disabled).toBe(true);
        expect(screen.getByRole('status').textContent).toContain('1 shift is open and 1 business day is open');
    });

    it('is read-only without the vendor unlock', async () => {
        capabilities = ['STORE_SETTINGS_MANAGE'];
        render(<SaleDocumentsPage />);
        await screen.findByText('IN USE');

        expect(screen.queryByRole('button', { name: /Switch to/ })).toBeNull();
        expect(screen.getByRole('note').textContent).toContain('locked');
    });

    it('saves the slip footer, and clearing it sends null', async () => {
        render(<SaleDocumentsPage />);
        const box = await screen.findByLabelText('Line printed at the bottom of every slip');

        fireEvent.change(box, { target: { value: 'Salamat po!' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save footer' }));
        await waitFor(() => expect(calls.some((call) => call.url === '/api/v1/sale-document-settings/slip-footer')).toBe(true));
        expect(calls.find((call) => call.url.endsWith('/slip-footer'))).toMatchObject({ method: 'PUT', body: { slip_footer: 'Salamat po!' } });

        fireEvent.change(box, { target: { value: '   ' } });
        fireEvent.click(await screen.findByRole('button', { name: 'Save footer' }));
        await waitFor(() => expect(calls.filter((call) => call.url.endsWith('/slip-footer')).length).toBe(2));
        expect(calls.filter((call) => call.url.endsWith('/slip-footer'))[1].body).toEqual({ slip_footer: null });
    });
});

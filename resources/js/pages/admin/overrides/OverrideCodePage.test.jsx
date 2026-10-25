import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import OverrideCodePage from './OverrideCodePage';
import OverrideCodeReset from './OverrideCodeReset';
import { CODE_HELP, statusText, validateCodeForm } from './overrideCodeForm';

// ADR-016 slice 3.3: the approver's own "My override code" page, and the administrator's "Clear override code" on the Users screen.

vi.mock('../AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
let calls;
let status;
let putAnswer;
let resetAnswer;

beforeEach(() => {
    calls = [];
    status = { user_id: 'u1', has_code: false, locked: false, locked_until: null, set_at: null };
    putAnswer = () => json({ ...status, has_code: true, set_at: '2026-10-26T09:00:00+08:00' });
    resetAnswer = () => json({ user_id: 'm1', has_code: false, locked: false, locked_until: null, set_at: null });
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options = {}) => {
            const method = options.method ?? 'GET';
            calls.push({ url, method, body: options.body ? JSON.parse(options.body) : undefined });
            if (url === '/api/v1/credit-override-code' && method === 'GET') {
                return json(status);
            }
            if (url === '/api/v1/credit-override-code' && method === 'PUT') {
                return putAnswer();
            }
            if (url === '/api/v1/credit-override-codes') {
                return json({ data: [{ user_id: 'm1', name: 'Mia Manager', role: 'MANAGER', active: true, has_code: true, locked: true, locked_until: new Date(Date.now() + 600000).toISOString(), set_at: '2026-10-20T09:00:00+08:00' }] });
            }
            if (url.endsWith('/override-code/reset')) {
                return resetAnswer();
            }
            return json({});
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
            <OverrideCodePage />
        </MemoryRouter>,
    );

describe('overrideCodeForm', () => {
    it('checks the obvious mistakes before sending', () => {
        expect(validateCodeForm({ password: '', code: '482916', confirm: '482916' })).toEqual({ password: 'Enter your account password.' });
        expect(validateCodeForm({ password: 'x', code: '4829', confirm: '4829' }).code).toMatch(/6 to 8 digits/);
        expect(validateCodeForm({ password: 'x', code: '482916', confirm: '482917' }).confirm).toMatch(/do not match/);
        expect(validateCodeForm({ password: 'x', code: '482916', confirm: '482916' })).toEqual({});
        expect(CODE_HELP).toMatch(/differ from your account password/);
    });

    it('reads a status in words', () => {
        expect(statusText({ has_code: false })).toBe('Not set');
        expect(statusText({ has_code: true, set_at: '2026-10-26T09:00:00+08:00' })).toMatch(/^Set on /);
        expect(statusText({ has_code: true, locked: true, locked_until: new Date().toISOString() })).toMatch(/^Locked after too many wrong codes/);
    });
});

describe('OverrideCodePage', () => {
    it('sets the code after the password, sends both once, clears the form and never shows the code', async () => {
        page();
        await waitFor(() => expect(screen.getByTestId('override-status').textContent).toBe('Not set'));

        fireEvent.change(screen.getByLabelText('Your account password'), { target: { value: 'my-password' } });
        fireEvent.change(screen.getByLabelText('Override code'), { target: { value: '482916' } });
        fireEvent.change(screen.getByLabelText('Type the code again'), { target: { value: '482916' } });
        expect(screen.getByLabelText('Override code').getAttribute('type')).toBe('password');
        fireEvent.click(screen.getByRole('button', { name: 'Save my code' }));

        await waitFor(() => expect(screen.getByText('Your override code is saved.')).toBeTruthy());
        expect(calls.filter((call) => call.method === 'PUT')).toEqual([{ url: '/api/v1/credit-override-code', method: 'PUT', body: { password: 'my-password', code: '482916' } }]);
        expect(screen.getByLabelText('Your account password').value).toBe('');
        expect(screen.getByLabelText('Type the code again').value).toBe('');
        expect(screen.getByTestId('override-status').textContent).toMatch(/^Set on /);
        expect(document.body.textContent).not.toContain('482916');
        expect(screen.getByRole('button', { name: 'Change my code' })).toBeTruthy();
    });

    it('does not send a code that does not match its confirmation, or a short one', async () => {
        page();
        await screen.findByTestId('override-status');
        fireEvent.change(screen.getByLabelText('Your account password'), { target: { value: 'pw' } });
        fireEvent.change(screen.getByLabelText('Override code'), { target: { value: '482916' } });
        fireEvent.change(screen.getByLabelText('Type the code again'), { target: { value: '482917' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save my code' }));

        expect((await screen.findAllByRole('alert'))[0].textContent).toMatch(/do not match/);
        expect(calls.some((call) => call.method === 'PUT')).toBe(false);
    });

    it('shows the server\'s reason (wrong password, an easy-to-guess code) next to the field and clears what was typed', async () => {
        putAnswer = () => json({ error: { code: 'VALIDATION_FAILED', message: 'x', details: { password: ['That password is not right.'] } } }, 422);
        page();
        await screen.findByTestId('override-status');
        fireEvent.change(screen.getByLabelText('Your account password'), { target: { value: 'wrong' } });
        fireEvent.change(screen.getByLabelText('Override code'), { target: { value: '482916' } });
        fireEvent.change(screen.getByLabelText('Type the code again'), { target: { value: '482916' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save my code' }));

        expect((await screen.findByText(/That password is not right/)).getAttribute('role')).toBe('alert');
        expect(screen.getByLabelText('Override code').value).toBe('');
    });

    it('says when too many tries were made', async () => {
        putAnswer = () => json({ error: { code: 'RATE_LIMITED', message: 'Too many attempts.' } }, 429);
        page();
        await screen.findByTestId('override-status');
        fireEvent.change(screen.getByLabelText('Your account password'), { target: { value: 'pw' } });
        fireEvent.change(screen.getByLabelText('Override code'), { target: { value: '482916' } });
        fireEvent.change(screen.getByLabelText('Type the code again'), { target: { value: '482916' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save my code' }));

        expect((await screen.findByText(/Too many tries/)).textContent).toMatch(/Wait a few minutes/);
    });
});

describe('OverrideCodeReset (the Users screen)', () => {
    it('is not shown for a cashier, who can never hold a code', () => {
        const { container } = render(<OverrideCodeReset user={{ id: 'c1', role: 'CASHIER' }} />);
        expect(container.textContent).toBe('');
        expect(calls).toEqual([]);
    });

    it('shows a manager\'s status and clears the code only after the administrator\'s own password', async () => {
        render(<OverrideCodeReset user={{ id: 'm1', role: 'MANAGER' }} />);
        await waitFor(() => expect(screen.getByTestId('override-status').textContent).toMatch(/^Locked/));

        fireEvent.click(screen.getByRole('button', { name: 'Clear override code' }));
        fireEvent.click(screen.getByRole('button', { name: 'Clear the code' }));
        expect(await screen.findByText(/Enter your own password to confirm\./)).toBeTruthy();
        expect(calls.some((call) => call.url.endsWith('/reset'))).toBe(false);

        fireEvent.change(screen.getByLabelText('Your password, to confirm'), { target: { value: 'admin-pw' } });
        fireEvent.click(screen.getByRole('button', { name: 'Clear the code' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toMatch(/Ask them to set a new code/));
        expect(calls.find((call) => call.url.endsWith('/reset'))).toEqual({ url: '/api/v1/users/m1/override-code/reset', method: 'POST', body: { password: 'admin-pw' } });
        expect(screen.getByTestId('override-status').textContent).toBe('Not set');
        expect(screen.queryByRole('button', { name: 'Clear override code' })).toBeNull();
    });

    it('shows a wrong administrator password as a field error and keeps the code', async () => {
        resetAnswer = () => json({ error: { code: 'VALIDATION_FAILED', message: 'x', details: { password: ['That password is not right.'] } } }, 422);
        render(<OverrideCodeReset user={{ id: 'm1', role: 'ADMIN' }} />);
        await screen.findByTestId('override-status');
        fireEvent.click(screen.getByRole('button', { name: 'Clear override code' }));
        fireEvent.change(screen.getByLabelText('Your password, to confirm'), { target: { value: 'nope' } });
        fireEvent.click(screen.getByRole('button', { name: 'Clear the code' }));

        const alert = await screen.findByText(/That password is not right/);
        expect(within(alert.closest('div')).getByLabelText('Your password, to confirm').value).toBe('');
        expect(screen.getByTestId('override-status').textContent).toMatch(/^Locked/);
    });
});

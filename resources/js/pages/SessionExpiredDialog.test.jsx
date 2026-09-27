import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const navigate = vi.fn();
const auth = { user: null, sessionExpired: false, setSessionExpired: vi.fn(), refresh: vi.fn(), logout: vi.fn() };
const request = vi.fn();

vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));
vi.mock('../context/AuthContext', () => ({ useAuth: () => auth }));
vi.mock('./admin/catalog/catalogApi', () => ({ request: (...args) => request(...args) }));

const { default: SessionExpiredDialog } = await import('./SessionExpiredDialog');

beforeEach(() => {
    Object.assign(auth, { user: { id: 'u1', email: 'cashier@shop.test' }, sessionExpired: true });
    auth.setSessionExpired.mockReset();
    auth.refresh.mockReset().mockResolvedValue();
    auth.logout.mockReset();
    navigate.mockReset();
    request.mockReset();
});
afterEach(cleanup);

const signInWith = (password) => {
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
};

describe('SessionExpiredDialog', () => {
    it('shows nothing while the session is fine, or when nobody was signed in', () => {
        auth.sessionExpired = false;
        const { container, rerender } = render(<SessionExpiredDialog />);
        expect(container.innerHTML).toBe('');

        Object.assign(auth, { sessionExpired: true, user: null });
        rerender(<SessionExpiredDialog />);
        expect(container.innerHTML).toBe('');
    });

    it('asks for the password over the screen, with the email already filled in', () => {
        render(<SessionExpiredDialog />);

        expect(screen.getByRole('alertdialog')).toBeTruthy();
        expect(screen.getByText('Your session expired')).toBeTruthy();
        expect(screen.getByLabelText('Email').value).toBe('cashier@shop.test');
        expect(screen.getByRole('button', { name: 'Sign in' }).disabled).toBe(true); // nothing typed yet
    });

    it('a wrong password shows a message and keeps the dialog open', async () => {
        request.mockResolvedValue({ ok: false, status: 401, body: null });
        render(<SessionExpiredDialog />);

        signInWith('nope');

        expect(await screen.findByText('Those details do not match. Try again.')).toBeTruthy();
        expect(auth.setSessionExpired).not.toHaveBeenCalled();
    });

    it('says so when the server is unreachable or the login is throttled', async () => {
        request.mockResolvedValueOnce({ ok: false, status: 0, body: null });
        render(<SessionExpiredDialog />);
        signInWith('pw');
        expect(await screen.findByText('Cannot reach the server. Check the connection and try again.')).toBeTruthy();

        request.mockResolvedValueOnce({ ok: false, status: 429, body: null });
        signInWith('pw');
        expect(await screen.findByText('Too many attempts. Wait a minute, then try again.')).toBeTruthy();
    });

    it('signing back in as the same user closes the dialog and leaves the screen where it was', async () => {
        request.mockResolvedValue({ ok: true, status: 200, body: { id: 'u1' } });
        render(<SessionExpiredDialog />);

        signInWith('right');

        await waitFor(() => expect(auth.setSessionExpired).toHaveBeenCalledWith(false));
        expect(auth.refresh).toHaveBeenCalled();
        expect(navigate).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledWith('/api/v1/auth/login', { method: 'POST', body: { email: 'cashier@shop.test', password: 'right' } });
    });

    it('signing in as a DIFFERENT user goes to the dashboard, because the open shift belongs to the previous user', async () => {
        request.mockResolvedValue({ ok: true, status: 200, body: { id: 'someone-else' } });
        render(<SessionExpiredDialog />);

        signInWith('right');

        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/', { replace: true }));
        expect(auth.setSessionExpired).toHaveBeenCalledWith(false);
    });

    it('offers to sign out instead', () => {
        render(<SessionExpiredDialog />);

        fireEvent.click(screen.getByRole('button', { name: 'Sign out instead' }));

        expect(auth.logout).toHaveBeenCalled();
    });
});

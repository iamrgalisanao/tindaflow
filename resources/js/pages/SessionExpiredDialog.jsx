import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { request } from './admin/catalog/catalogApi';

/**
 * Shown over whatever screen is open when the signed-in user's session has expired (a till left idle past
 * SESSION_LIFETIME). It is mounted above the routes, so nothing behind it unmounts: a half-built basket, a cash count or
 * an unsaved form is exactly as it was, and after signing in the cashier simply presses the button again (a sale's
 * Idempotency-Key was dropped on the 401, because nothing was saved). Signing in as a DIFFERENT user goes back to the
 * dashboard, because the open shift belongs to the previous user.
 */
export default function SessionExpiredDialog() {
    const { user, sessionExpired, setSessionExpired, refresh, logout } = useAuth();
    const navigate = useNavigate();
    const passwordRef = useRef(null);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (sessionExpired) {
            setEmail(user?.email ?? '');
            setPassword('');
            setError(null);
            passwordRef.current?.focus();
        }
    }, [sessionExpired, user?.email]);

    if (!sessionExpired || !user) {
        return null;
    }

    async function signIn(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const result = await request('/api/v1/auth/login', { method: 'POST', body: { email, password } });

        if (result.ok) {
            const differentUser = result.body?.id !== user.id;
            await refresh();
            setSessionExpired(false);
            if (differentUser) {
                navigate('/', { replace: true });
            }
            return;
        }

        if (result.status === 429) {
            setError('Too many attempts. Wait a minute, then try again.');
        } else if (result.status === 0) {
            setError('Cannot reach the server. Check the connection and try again.');
        } else if (result.status === 401 || result.status === 422) {
            setError('Those details do not match. Try again.');
        } else {
            setError('Could not sign in. Try again.');
        }
        setBusy(false);
    }

    const field = 'min-h-12 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4 [color-scheme:dark]" role="alertdialog" aria-modal="true" aria-labelledby="session-expired-title">
            <div className="absolute inset-0 bg-slate-950/90 backdrop-blur-sm" aria-hidden="true" />
            <form onSubmit={signIn} className="relative w-full max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]">
                <h3 id="session-expired-title" className="text-base font-semibold text-slate-100">
                    Your session expired
                </h3>
                <p className="text-sm text-slate-400">Sign in again to carry on. Whatever is on the screen behind, such as a basket, is kept.</p>

                {error && (
                    <p role="alert" className="rounded-md border border-rose-800/60 bg-rose-950/40 px-3 py-2 text-sm text-rose-300">
                        {error}
                    </p>
                )}

                <div>
                    <label htmlFor="expired-email" className="mb-1 block text-xs text-slate-400">
                        Email
                    </label>
                    <input id="expired-email" type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} className={field} />
                </div>
                <div>
                    <label htmlFor="expired-password" className="mb-1 block text-xs text-slate-400">
                        Password
                    </label>
                    <input
                        id="expired-password"
                        ref={passwordRef}
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        className={field}
                    />
                </div>

                <button
                    type="submit"
                    disabled={busy || password === ''}
                    className="min-h-12 w-full rounded-md bg-emerald-500 px-4 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {busy ? 'Signing in…' : 'Sign in'}
                </button>
                <button type="button" onClick={logout} className="w-full text-center text-xs text-slate-500 underline hover:text-slate-300">
                    Sign out instead
                </button>
            </form>
        </div>
    );
}

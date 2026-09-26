import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiFetch } from '../api';
import { useAuth } from '../context/AuthContext';

/**
 * A report viewer whose session expired stores its own URL here before sending the user to sign in.
 * Only same-app /admin/ paths are honoured, so this can never redirect off-site.
 */
function consumeReturnTo() {
    try {
        const target = sessionStorage.getItem('tindaflow.returnTo');
        sessionStorage.removeItem('tindaflow.returnTo');
        if (target && target.startsWith('/admin/') && !target.startsWith('//')) {
            return target;
        }
    } catch {
        // Session storage can be unavailable; fall through to the dashboard.
    }
    return '/';
}

/**
 * openapi.yaml authLogin. Surfaces the frozen error-catalog codes
 * distinctly (VALIDATION_FAILED field errors, AUTHENTICATION_REQUIRED,
 * RATE_LIMITED) rather than a single generic failure message -- matching
 * what the backend already deliberately distinguishes.
 */
export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const { refresh } = useAuth();
    const navigate = useNavigate();

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setFieldErrors({});
        setFormError(null);

        const { ok, status, body } = await apiFetch('/api/v1/auth/login', {
            method: 'POST',
            body: { email, password },
        });

        if (ok) {
            await refresh();
            navigate(consumeReturnTo(), { replace: true });
            return;
        }

        if (status === 422 && body?.error?.code === 'VALIDATION_FAILED') {
            setFieldErrors(body.error.details || {});
        } else if (status === 429) {
            setFormError('Too many attempts. Please try again later.');
        } else {
            setFormError('These credentials do not match our records.');
        }

        setSubmitting(false);
    }

    const field = 'min-h-12 w-full rounded-md border bg-slate-950 px-3 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1';

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-950 px-4 text-slate-100 [color-scheme:dark]">
            <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-6">
                <div className="flex items-center gap-3 border-b border-slate-800 pb-4">
                    <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-emerald-500 text-sm font-bold text-slate-950">
                        TF
                    </span>
                    <div>
                        <h1 className="text-sm font-bold tracking-wide text-slate-100">TINDAFLOW</h1>
                        <p className="font-mono text-[10px] tracking-[0.2em] text-emerald-400">SIGN IN</p>
                    </div>
                </div>

                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800/60 bg-rose-950/40 px-3 py-2 text-sm text-rose-300">
                        {formError}
                    </p>
                )}

                <div>
                    <label htmlFor="email" className="mb-1 block text-xs text-slate-400">
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={email}
                        onChange={(event) => setEmail(event.target.value)}
                        aria-invalid={fieldErrors.email ? 'true' : undefined}
                        aria-describedby={fieldErrors.email ? 'email_error' : undefined}
                        className={`${field} ${fieldErrors.email ? 'border-rose-700 focus:border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'}`}
                    />
                    {fieldErrors.email && (
                        <p id="email_error" className="mt-1 text-xs text-rose-400">
                            {fieldErrors.email[0]}
                        </p>
                    )}
                </div>

                <div>
                    <label htmlFor="password" className="mb-1 block text-xs text-slate-400">
                        Password
                    </label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        aria-invalid={fieldErrors.password ? 'true' : undefined}
                        aria-describedby={fieldErrors.password ? 'password_error' : undefined}
                        className={`${field} ${fieldErrors.password ? 'border-rose-700 focus:border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'}`}
                    />
                    {fieldErrors.password && (
                        <p id="password_error" className="mt-1 text-xs text-rose-400">
                            {fieldErrors.password[0]}
                        </p>
                    )}
                </div>

                <button
                    type="submit"
                    disabled={submitting}
                    className="min-h-12 w-full rounded-md bg-emerald-500 px-4 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500"
                >
                    {submitting ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </div>
    );
}

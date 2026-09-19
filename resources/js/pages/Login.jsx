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

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
            <form
                onSubmit={handleSubmit}
                className="w-full max-w-sm space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
            >
                <h1 className="text-lg font-semibold text-gray-900">TindaFlow</h1>

                {formError && (
                    <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{formError}</p>
                )}

                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={email}
                        onChange={(event) => setEmail(event.target.value)}
                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                    />
                    {fieldErrors.email && (
                        <p className="mt-1 text-sm text-red-600">{fieldErrors.email[0]}</p>
                    )}
                </div>

                <div>
                    <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                        Password
                    </label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                    />
                    {fieldErrors.password && (
                        <p className="mt-1 text-sm text-red-600">{fieldErrors.password[0]}</p>
                    )}
                </div>

                <button
                    type="submit"
                    disabled={submitting}
                    className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                >
                    {submitting ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </div>
    );
}

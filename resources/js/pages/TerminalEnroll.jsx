import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiFetch } from '../api';
import { useAuth } from '../context/AuthContext';

/**
 * Back-office terminal management (TERMINAL_MANAGE only): list this
 * store's terminals, generate a one-time enrollment token for one, and
 * enroll THIS browser using a token (either the one just generated, or
 * pasted from another admin session). Needed before /pos can do
 * anything -- every POS_TERMINAL-classified operation (shiftOpen,
 * sales, shifts/current) requires the tindaflow_terminal credential
 * ADR-011 establishes here.
 *
 * No terminal-create screen exists yet (no productCreate-equivalent
 * backend endpoint for Terminal either) -- terminals must already exist
 * (seeded) before this screen can enroll one.
 */
export default function TerminalEnroll() {
    const { user } = useAuth();
    const [terminals, setTerminals] = useState(null);
    const [error, setError] = useState(null);
    const [issuedToken, setIssuedToken] = useState(null);
    const [pastedToken, setPastedToken] = useState('');
    const [busy, setBusy] = useState(false);
    const [enrolledAs, setEnrolledAs] = useState(null);

    const loadTerminals = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/terminals');
        if (ok) {
            setTerminals(body.data);
        } else {
            setError(body?.error?.message ?? 'Could not load terminals.');
        }
    }, []);

    useEffect(() => {
        loadTerminals();
        apiFetch('/api/v1/terminal/current').then(({ ok, body }) => {
            if (ok) {
                setEnrolledAs(body);
            }
        });
    }, [loadTerminals]);

    async function generateToken(terminalId) {
        setBusy(true);
        setError(null);
        const { ok, body } = await apiFetch('/api/v1/terminal-enrollment-tokens', {
            method: 'POST',
            body: { terminal_id: terminalId },
        });
        if (ok) {
            setIssuedToken({ terminalId, token: body.token, expiresAt: body.expires_at });
        } else {
            setError(body?.error?.message ?? 'Could not generate a token.');
        }
        setBusy(false);
    }

    async function enrollThisBrowser(token) {
        setBusy(true);
        setError(null);
        const { ok, body } = await apiFetch('/api/v1/terminal/enroll', {
            method: 'POST',
            body: { token },
        });
        if (ok) {
            setEnrolledAs(body);
            setIssuedToken(null);
            setPastedToken('');
        } else {
            setError(body?.error?.message ?? 'Enrollment failed — the token may be invalid, expired, or already used.');
        }
        setBusy(false);
    }

    if (!user.capabilities.includes('TERMINAL_MANAGE')) {
        return (
            <div className="mx-auto max-w-lg p-6">
                <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    Your role does not include terminal management.
                </p>
                <Link to="/" className="mt-4 inline-block text-sm text-gray-600 underline">
                    Back
                </Link>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-gray-50 px-4 py-8">
            <div className="mx-auto max-w-xl space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold text-gray-900">Terminal Enrollment</h1>
                    <Link to="/" className="text-sm text-gray-600 underline">
                        Back
                    </Link>
                </div>

                {error && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-2 text-sm font-medium text-gray-700">This browser</h2>
                    {enrolledAs ? (
                        <p className="text-sm text-green-700">
                            Enrolled as terminal <span className="font-mono">{enrolledAs.terminal_code}</span> ({enrolledAs.status})
                        </p>
                    ) : (
                        <p className="text-sm text-gray-500">Not enrolled as any terminal yet.</p>
                    )}
                </div>

                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-medium text-gray-700">Store terminals</h2>
                    {terminals === null && <p className="text-sm text-gray-500">Loading…</p>}
                    {terminals?.length === 0 && <p className="text-sm text-gray-500">No terminals exist for this store yet.</p>}
                    <ul className="space-y-2">
                        {terminals?.map((terminal) => (
                            <li key={terminal.id} className="flex items-center justify-between rounded-md border border-gray-100 px-3 py-2">
                                <span className="text-sm">
                                    <span className="font-mono">{terminal.terminal_code}</span>{' '}
                                    <span className="text-gray-500">({terminal.status})</span>
                                </span>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => generateToken(terminal.id)}
                                    className="rounded-md border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Generate enrollment token
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>

                {issuedToken && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p className="mb-2 text-sm text-amber-800">
                            Token (shown once — copy it, or enroll this same browser now):
                        </p>
                        <code className="block break-all rounded bg-white px-2 py-1 text-xs">{issuedToken.token}</code>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => enrollThisBrowser(issuedToken.token)}
                            className="mt-3 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            Enroll this browser now
                        </button>
                    </div>
                )}

                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-2 text-sm font-medium text-gray-700">Enroll this browser with a token</h2>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={pastedToken}
                            onChange={(event) => setPastedToken(event.target.value)}
                            placeholder="Paste an enrollment token"
                            className="flex-1 rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                        />
                        <button
                            type="button"
                            disabled={busy || !pastedToken}
                            onClick={() => enrollThisBrowser(pastedToken)}
                            className="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            Enroll
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

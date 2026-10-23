import { useCallback, useEffect, useRef, useState } from 'react';
import { apiFetch } from '../../api';

const STORAGE_KEY = 'tindaflow.pairing';
const POLL_MS = 3000;

function loadSaved() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null');
        return saved && new Date(saved.expiresAt) > new Date() ? saved : null;
    } catch {
        return null;
    }
}

function save(request) {
    try {
        if (request) {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(request));
        } else {
            sessionStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Private windows can refuse storage; the request still works until the page is reloaded.
    }
}

/**
 * ADR-015: an un-enrolled browser asks to become a till. The signed-in person is shown a short code to read out; an administrator,
 * wherever they are, approves that code and chooses the terminal; this page notices within a few seconds and reloads as the till.
 * The code is for the administrator to match to a person; the secret that lets this page collect the credential never leaves it.
 */
export default function PairingRequestPanel() {
    const [request, setRequest] = useState(loadSaved);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const polling = useRef(false);

    const ask = useCallback(async () => {
        setBusy(true);
        setError(null);
        const response = await apiFetch('/api/v1/terminal/pairing-requests', { method: 'POST' });
        if (response.ok) {
            const next = { id: response.body.id, code: response.body.code, secret: response.body.secret, expiresAt: response.body.expires_at };
            save(next);
            setRequest(next);
        } else {
            setError(response.body?.error?.message ?? 'The request could not be sent. Check the connection and try again.');
        }
        setBusy(false);
    }, []);

    const cancel = useCallback(() => {
        save(null);
        setRequest(null);
    }, []);

    useEffect(() => {
        if (!request) {
            return undefined;
        }
        const timer = setInterval(async () => {
            if (polling.current) {
                return;
            }
            polling.current = true;
            try {
                if (new Date(request.expiresAt) <= new Date()) {
                    cancel();
                    setError('The request ran out of time. Ask again.');
                    return;
                }
                const response = await apiFetch(`/api/v1/terminal/pairing-requests/${request.id}/claim`, { method: 'POST', body: { secret: request.secret } });
                if (response.status === 200) {
                    save(null);
                    window.location.reload();
                } else if (response.status === 409) {
                    cancel();
                    setError('The request was declined or ran out of time. Ask again.');
                }
            } finally {
                polling.current = false;
            }
        }, POLL_MS);

        return () => clearInterval(timer);
    }, [request, cancel]);

    return (
        <div className="mt-6 rounded-lg border border-slate-700 bg-slate-900 p-4 text-left">
            {!request ? (
                <>
                    <h2 className="text-sm font-semibold text-slate-100">Is this the shop’s till computer?</h2>
                    <p className="mt-1 text-xs text-slate-400">
                        An administrator does not have to be here. Ask for approval, then read the code that appears to them (a phone call is enough).
                    </p>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={ask}
                        className="mt-3 w-full rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                    >
                        {busy ? 'Sending…' : 'Ask an admin to approve this computer'}
                    </button>
                </>
            ) : (
                <div aria-live="polite">
                    <h2 className="text-sm font-semibold text-slate-100">Waiting for an administrator</h2>
                    <p className="mt-1 text-xs text-slate-400">Read this code to the administrator. Keep this page open; it connects by itself once they approve.</p>
                    <p className="my-4 text-center font-mono text-4xl font-bold tracking-widest text-emerald-400" aria-label={`Code ${request.code.split('').join(' ')}`}>
                        {request.code}
                    </p>
                    <button type="button" onClick={cancel} className="w-full rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800">
                        Cancel this request
                    </button>
                </div>
            )}
            {error && (
                <p role="alert" className="mt-3 rounded-md bg-red-500/10 px-3 py-2 text-xs text-red-300">
                    {error}
                </p>
            )}
        </div>
    );
}

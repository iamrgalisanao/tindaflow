import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';

export default function TaxRegistrations() {
    const [registrations, setRegistrations] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const [registrationType, setRegistrationType] = useState('VAT');
    const [effectiveFrom, setEffectiveFrom] = useState('');

    const load = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/tax-registrations');
        if (ok) {
            setRegistrations(body);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function createRegistration(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch('/api/v1/tax-registrations', {
            method: 'POST',
            body: { registration_type: registrationType, effective_from: effectiveFrom },
        });

        if (ok) {
            setEffectiveFrom('');
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not create the tax registration.');
        }
        setBusy(false);
    }

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Tax Registrations</h2>
            <p className="mb-4 text-sm text-slate-400">
                Registering a new one closes the current registration the day before this one starts — history is never overwritten.
            </p>

            {error && <p className="mb-4 rounded-md border border-rose-800 bg-rose-950 px-3 py-2 text-sm text-rose-300">{error}</p>}

            <form onSubmit={createRegistration} className="mb-6 flex flex-wrap gap-3 rounded-lg border border-slate-800 bg-slate-900 p-4">
                <select
                    value={registrationType}
                    onChange={(event) => setRegistrationType(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                >
                    <option value="VAT">VAT</option>
                    <option value="NON_VAT">NON_VAT</option>
                </select>
                <input
                    type="date"
                    required
                    value={effectiveFrom}
                    onChange={(event) => setEffectiveFrom(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                />
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                >
                    Register
                </button>
            </form>

            {registrations === null && <p className="text-sm text-slate-500">Loading…</p>}
            {registrations?.length === 0 && <p className="text-sm text-slate-500">No tax registrations yet.</p>}

            <div className="overflow-x-auto rounded-lg border border-slate-800">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2 font-mono">Effective from</th>
                            <th className="px-3 py-2 font-mono">Effective to</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800">
                        {registrations?.map((row) => (
                            <tr key={row.id} className="bg-slate-950/50">
                                <td className="px-3 py-2 text-slate-100">{row.registration_type}</td>
                                <td className="px-3 py-2 font-mono text-slate-300">{row.effective_from}</td>
                                <td className="px-3 py-2 font-mono text-slate-500">
                                    {row.effective_to ?? (
                                        <span className="rounded border border-emerald-700 bg-emerald-900/40 px-2 py-0.5 text-[11px] uppercase text-emerald-400">
                                            Current
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminLayout>
    );
}

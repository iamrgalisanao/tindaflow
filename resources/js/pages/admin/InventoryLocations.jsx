import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';

export default function InventoryLocations() {
    const [locations, setLocations] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [name, setName] = useState('');

    const load = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/inventory-locations?per_page=100');
        if (ok) {
            setLocations(body.data);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function createLocation(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch('/api/v1/inventory-locations', { method: 'POST', body: { name } });

        if (ok) {
            setName('');
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not create the location.');
        }
        setBusy(false);
    }

    async function makeDefault(id) {
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch(`/api/v1/inventory-locations/${id}`, {
            method: 'PATCH',
            body: { is_default: true },
        });

        if (ok) {
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not update the location.');
        }
        setBusy(false);
    }

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Inventory Locations</h2>
            <p className="mb-4 text-sm text-slate-400">
                The default location is where a POS sale deducts stock from. A store's first location becomes the default automatically.
            </p>

            {error && <p className="mb-4 rounded-md border border-rose-800 bg-rose-950 px-3 py-2 text-sm text-rose-300">{error}</p>}

            <form onSubmit={createLocation} className="mb-6 flex gap-3 rounded-lg border border-slate-800 bg-slate-900 p-4">
                <input
                    type="text"
                    required
                    placeholder="Location name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    className="flex-1 rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                >
                    Add location
                </button>
            </form>

            {locations === null && <p className="text-sm text-slate-500">Loading…</p>}
            {locations?.length === 0 && <p className="text-sm text-slate-500">No inventory locations yet.</p>}

            <ul className="space-y-2">
                {locations?.map((location) => (
                    <li
                        key={location.id}
                        className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900 px-4 py-3"
                    >
                        <p className="text-sm text-slate-100">{location.name}</p>
                        <div>
                            {location.is_default ? (
                                <span className="rounded border border-emerald-700 bg-emerald-900/40 px-2 py-0.5 font-mono text-[11px] uppercase text-emerald-400">
                                    Default
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => makeDefault(location.id)}
                                    className="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-300 hover:bg-slate-800 disabled:opacity-50"
                                >
                                    Make default
                                </button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </AdminLayout>
    );
}

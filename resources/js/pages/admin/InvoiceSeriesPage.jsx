import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';

export default function InvoiceSeriesPage() {
    const [series, setSeries] = useState(null);
    const [installations, setInstallations] = useState([]);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const [fiscalInstallationId, setFiscalInstallationId] = useState('');
    const [seriesCode, setSeriesCode] = useState('');
    const [prefix, setPrefix] = useState('');
    const [startingNumber, setStartingNumber] = useState('1');
    const [endingNumber, setEndingNumber] = useState('');

    const load = useCallback(async () => {
        const [seriesRes, installationsRes] = await Promise.all([
            apiFetch('/api/v1/invoice-series?per_page=100'),
            apiFetch('/api/v1/fiscal-installations'),
        ]);
        if (seriesRes.ok) {
            setSeries(seriesRes.body.data);
        }
        if (installationsRes.ok) {
            setInstallations(installationsRes.body);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function createSeries(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch('/api/v1/invoice-series', {
            method: 'POST',
            body: {
                fiscal_installation_id: fiscalInstallationId,
                series_code: seriesCode,
                prefix: prefix || null,
                starting_number: Number(startingNumber),
                ending_number: endingNumber ? Number(endingNumber) : null,
            },
        });

        if (ok) {
            setSeriesCode('');
            setPrefix('');
            setStartingNumber('1');
            setEndingNumber('');
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not activate the invoice series.');
        }
        setBusy(false);
    }

    async function closeSeries(id) {
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch(`/api/v1/invoice-series/${id}/close`, { method: 'POST' });

        if (ok) {
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not close the invoice series.');
        }
        setBusy(false);
    }

    function installationLabel(id) {
        const installation = installations.find((fi) => fi.id === id);
        return installation ? `${installation.deployment_model} (v${installation.software_version})` : id;
    }

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Invoice Series</h2>
            <p className="mb-4 text-sm text-slate-400">
                At most one ACTIVE series per fiscal installation. Close the current one before activating another.
            </p>

            {error && <p className="mb-4 rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-300">{error}</p>}

            <form onSubmit={createSeries} className="mb-6 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-4 sm:grid-cols-5">
                <select
                    required
                    value={fiscalInstallationId}
                    onChange={(event) => setFiscalInstallationId(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                >
                    <option value="">Fiscal installation…</option>
                    {installations.map((installation) => (
                        <option key={installation.id} value={installation.id}>
                            {installationLabel(installation.id)}
                        </option>
                    ))}
                </select>
                <input
                    type="text"
                    required
                    placeholder="Series code"
                    value={seriesCode}
                    onChange={(event) => setSeriesCode(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <input
                    type="text"
                    placeholder="Prefix"
                    value={prefix}
                    onChange={(event) => setPrefix(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <input
                    type="number"
                    required
                    min="1"
                    placeholder="Starting #"
                    value={startingNumber}
                    onChange={(event) => setStartingNumber(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <div className="flex gap-2">
                    <input
                        type="number"
                        placeholder="Ending # (optional)"
                        value={endingNumber}
                        onChange={(event) => setEndingNumber(event.target.value)}
                        className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                    />
                    <button
                        type="submit"
                        disabled={busy}
                        className="whitespace-nowrap rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                    >
                        Activate
                    </button>
                </div>
            </form>

            {series === null && <p className="text-sm text-slate-500">Loading…</p>}
            {series?.length === 0 && <p className="text-sm text-slate-500">No invoice series yet.</p>}

            <div className="overflow-x-auto rounded-lg border border-slate-800">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Series</th>
                            <th className="px-3 py-2">Installation</th>
                            <th className="px-3 py-2 text-right font-mono">Current #</th>
                            <th className="px-3 py-2 text-right font-mono">Range</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800">
                        {series?.map((row) => (
                            <tr key={row.id} className="bg-slate-950/50">
                                <td className="px-3 py-2 font-mono text-slate-100">
                                    {row.prefix}
                                    {row.series_code}
                                </td>
                                <td className="px-3 py-2 text-xs text-slate-400">{installationLabel(row.fiscal_installation_id)}</td>
                                <td className="px-3 py-2 text-right font-mono text-slate-300">{row.current_number}</td>
                                <td className="px-3 py-2 text-right font-mono text-slate-500">
                                    {row.starting_number}–{row.ending_number ?? '∞'}
                                </td>
                                <td className="px-3 py-2">
                                    <span
                                        className={`rounded px-2 py-0.5 font-mono text-[11px] uppercase ${
                                            row.status === 'ACTIVE'
                                                ? 'border border-emerald-700 bg-emerald-900/40 text-emerald-400'
                                                : 'border border-slate-700 bg-slate-800 text-slate-400'
                                        }`}
                                    >
                                        {row.status}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {row.status === 'ACTIVE' && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => closeSeries(row.id)}
                                            className="text-xs text-red-400 underline disabled:opacity-50"
                                        >
                                            Close
                                        </button>
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

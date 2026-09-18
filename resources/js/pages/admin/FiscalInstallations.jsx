import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';

const DEPLOYMENT_MODELS = ['STANDALONE', 'SERVER_CONNECTED'];

export default function FiscalInstallations() {
    const [installations, setInstallations] = useState(null);
    const [terminals, setTerminals] = useState([]);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const [deploymentModel, setDeploymentModel] = useState('STANDALONE');
    const [softwareVersion, setSoftwareVersion] = useState('');
    const [machineSerialNumber, setMachineSerialNumber] = useState('');

    const [assignTerminalId, setAssignTerminalId] = useState({});

    const load = useCallback(async () => {
        const [installationsRes, terminalsRes] = await Promise.all([
            apiFetch('/api/v1/fiscal-installations'),
            apiFetch('/api/v1/terminals?per_page=100'),
        ]);
        if (installationsRes.ok) {
            setInstallations(installationsRes.body);
        }
        if (terminalsRes.ok) {
            setTerminals(terminalsRes.body.data);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function createInstallation(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch('/api/v1/fiscal-installations', {
            method: 'POST',
            body: {
                deployment_model: deploymentModel,
                software_version: softwareVersion,
                machine_serial_number: machineSerialNumber || null,
            },
        });

        if (ok) {
            setSoftwareVersion('');
            setMachineSerialNumber('');
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not create the fiscal installation.');
        }
        setBusy(false);
    }

    async function assignTerminal(installationId) {
        const terminalId = assignTerminalId[installationId];
        if (!terminalId) {
            return;
        }
        setBusy(true);
        setError(null);

        const { ok, body } = await apiFetch(`/api/v1/fiscal-installations/${installationId}/terminals`, {
            method: 'POST',
            body: { terminal_id: terminalId },
        });

        if (ok) {
            await load();
        } else {
            setError(body?.error?.message ?? 'Could not assign the terminal.');
        }
        setBusy(false);
    }

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Fiscal Installations</h2>
            <p className="mb-4 text-sm text-slate-400">
                Each terminal must be assigned to exactly one current fiscal installation before it can check out.
            </p>

            {error && <p className="mb-4 rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-300">{error}</p>}

            <form onSubmit={createInstallation} className="mb-6 grid grid-cols-1 gap-3 rounded-lg border border-slate-800 bg-slate-900 p-4 sm:grid-cols-4">
                <select
                    value={deploymentModel}
                    onChange={(event) => setDeploymentModel(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                >
                    {DEPLOYMENT_MODELS.map((model) => (
                        <option key={model} value={model}>
                            {model}
                        </option>
                    ))}
                </select>
                <input
                    type="text"
                    required
                    placeholder="Software version"
                    value={softwareVersion}
                    onChange={(event) => setSoftwareVersion(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <input
                    type="text"
                    placeholder="Machine serial number (optional)"
                    value={machineSerialNumber}
                    onChange={(event) => setMachineSerialNumber(event.target.value)}
                    className="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                >
                    Add installation
                </button>
            </form>

            {installations === null && <p className="text-sm text-slate-500">Loading…</p>}
            {installations?.length === 0 && <p className="text-sm text-slate-500">No fiscal installations yet.</p>}

            <ul className="space-y-3">
                {installations?.map((installation) => (
                    <li key={installation.id} className="rounded-lg border border-slate-800 bg-slate-900 p-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="font-mono text-sm text-slate-100">{installation.deployment_model}</p>
                                <p className="text-xs text-slate-500">
                                    v{installation.software_version}
                                    {installation.machine_serial_number && ` · SN ${installation.machine_serial_number}`}
                                </p>
                            </div>
                            <span className="font-mono text-xs text-slate-500">
                                {installation.terminals.length} terminal{installation.terminals.length === 1 ? '' : 's'}
                            </span>
                        </div>

                        <div className="mt-3 flex gap-2 border-t border-slate-800 pt-3">
                            <select
                                value={assignTerminalId[installation.id] ?? ''}
                                onChange={(event) =>
                                    setAssignTerminalId((prior) => ({ ...prior, [installation.id]: event.target.value }))
                                }
                                className="flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-slate-100"
                            >
                                <option value="">Assign a terminal…</option>
                                {terminals.map((terminal) => (
                                    <option key={terminal.id} value={terminal.id}>
                                        {terminal.terminal_code}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="button"
                                disabled={busy || !assignTerminalId[installation.id]}
                                onClick={() => assignTerminal(installation.id)}
                                className="rounded-md border border-emerald-700 px-3 py-1.5 text-xs text-emerald-400 hover:bg-emerald-900/40 disabled:opacity-50"
                            >
                                Assign
                            </button>
                        </div>
                    </li>
                ))}
            </ul>
        </AdminLayout>
    );
}

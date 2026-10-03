import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';
import { useCanConfigureFiscal } from './FiscalLockNotice';

/**
 * Store-level readiness, asked of the server (GET /store-setup/overview) with the very checks the till runs, so this page
 * can never say READY about a store whose till is blocked. It used to guess in the browser ("any installation has a
 * terminal", "any series is active"), which is how the two came to disagree. The terminal-scoped GET /store-setup/readiness
 * stays the till's own; this one needs only a session, because an admin may have no terminal enrolled on this browser.
 */
const CHECKS = [
    { key: 'fiscal_installation', label: 'Fiscal Installation', to: '/admin/store-setup/fiscal-installations', hint: 'Every till is assigned to a fiscal installation that is in effect now.' },
    { key: 'invoice_series', label: 'Invoice Series', to: '/admin/store-setup/invoice-series', hint: 'Each till’s fiscal installation has an ACTIVE invoice series.' },
    { key: 'inventory_location', label: 'Inventory Location', to: '/admin/store-setup/inventory-locations', hint: 'A default selling location is set.' },
    { key: 'tax_registration', label: 'Tax Registration', to: '/admin/store-setup/tax-registrations', hint: 'A tax registration covers today.' },
];

const PER_TERMINAL = ['fiscal_installation', 'invoice_series'];

export default function StoreSetupOverview() {
    const [overview, setOverview] = useState(null);
    const [error, setError] = useState(null);
    const canConfigure = useCanConfigureFiscal();

    useEffect(() => {
        (async () => {
            const response = await apiFetch('/api/v1/store-setup/overview');

            if (!response.ok) {
                setError('Could not load store-setup status.');
                return;
            }

            setOverview(response.body);
        })();
    }, []);

    const checks = overview?.checks ?? null;
    const terminals = overview?.terminals ?? [];

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Readiness</h2>
            <p className="mb-4 text-sm text-slate-400">
                What a fresh store needs before a POS terminal can complete a checkout.
            </p>

            {error && <p className="mb-4 rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-300">{error}</p>}

            {checks === null && !error && <p className="text-sm text-slate-500">Loading…</p>}

            {checks && !canConfigure && Object.entries(checks).some(([key, ready]) => !ready && key !== 'inventory_location') && (
                <p role="note" className="mb-4 rounded-md border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-sm text-amber-300">
                    The tax and invoice items below are locked and set during installation. If one is incomplete, ask your TindaFlow provider to finish it.
                    Your stock locations and business details you can change yourself.
                </p>
            )}

            {checks && (
                <ul className="space-y-2">
                    {CHECKS.map((check) => {
                        const ready = checks[check.key];
                        return (
                            <li
                                key={check.key}
                                className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900 px-4 py-3"
                            >
                                <div>
                                    <p className="text-sm font-medium text-slate-100">{check.label}</p>
                                    <p className="text-xs text-slate-500">{check.hint}</p>
                                    {!ready && PER_TERMINAL.includes(check.key) && terminals.length === 0 && (
                                        <p className="mt-1 text-xs text-amber-400">
                                            No till can sell yet. <Link to="/admin/terminals" className="underline">Add and enroll a terminal</Link> first.
                                        </p>
                                    )}
                                    {!ready && PER_TERMINAL.includes(check.key) && terminals.some((terminal) => !terminal.checks[check.key]) && (
                                        <p className="mt-1 text-xs text-amber-400">
                                            Not ready for: {terminals.filter((terminal) => !terminal.checks[check.key]).map((terminal) => terminal.terminal_code).join(', ')}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span
                                        className={`rounded px-2 py-0.5 font-mono text-[11px] uppercase tracking-wide ${
                                            ready
                                                ? 'border border-emerald-700 bg-emerald-900/40 text-emerald-400'
                                                : 'border border-amber-700 bg-amber-900/40 text-amber-400'
                                        }`}
                                    >
                                        {ready ? 'Ready' : 'Incomplete'}
                                    </span>
                                    <Link to={check.to} className="text-xs text-slate-400 underline hover:text-slate-200">
                                        {check.key === 'inventory_location' || canConfigure ? 'Manage' : 'View'}
                                    </Link>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </AdminLayout>
    );
}

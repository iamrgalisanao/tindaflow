import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiFetch } from '../../api';
import AdminLayout from './AdminLayout';

/**
 * Store-level completeness summary, derived client-side from the four
 * list endpoints rather than the terminal-scoped GET /store-setup/
 * readiness -- an admin browsing this page may not have any terminal
 * enrolled on this browser at all, so that endpoint (which 403s without
 * one) isn't the right fit here. The POS screen uses the terminal-scoped
 * endpoint instead, where a terminal credential is always already
 * present.
 */
const CHECKS = [
    { key: 'fiscal_installation', label: 'Fiscal Installation', to: '/admin/store-setup/fiscal-installations', hint: 'At least one fiscal installation recorded, with a terminal assigned to it.' },
    { key: 'invoice_series', label: 'Invoice Series', to: '/admin/store-setup/invoice-series', hint: 'At least one ACTIVE invoice series for an installation.' },
    { key: 'inventory_location', label: 'Inventory Location', to: '/admin/store-setup/inventory-locations', hint: 'A default selling location is set.' },
    { key: 'tax_registration', label: 'Tax Registration', to: '/admin/store-setup/tax-registrations', hint: 'A current (not-yet-closed) tax registration exists.' },
];

export default function StoreSetupOverview() {
    const [checks, setChecks] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        (async () => {
            const [installations, series, locations, registrations] = await Promise.all([
                apiFetch('/api/v1/fiscal-installations'),
                apiFetch('/api/v1/invoice-series?per_page=100'),
                apiFetch('/api/v1/inventory-locations?per_page=100'),
                apiFetch('/api/v1/tax-registrations'),
            ]);

            if (![installations, series, locations, registrations].every((r) => r.ok)) {
                setError('Could not load store-setup status.');
                return;
            }

            const installationsWithTerminal = installations.body.filter((fi) => fi.terminals.length > 0);

            setChecks({
                fiscal_installation: installationsWithTerminal.length > 0,
                invoice_series: series.body.data.some((s) => s.status === 'ACTIVE'),
                inventory_location: locations.body.data.some((l) => l.is_default),
                tax_registration: registrations.body.some((r) => r.effective_to === null),
            });
        })();
    }, []);

    return (
        <AdminLayout>
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Readiness</h2>
            <p className="mb-4 text-sm text-slate-400">
                What a fresh store needs before a POS terminal can complete a checkout.
            </p>

            {error && <p className="mb-4 rounded-md border border-rose-800 bg-rose-950 px-3 py-2 text-sm text-rose-300">{error}</p>}

            {checks === null && !error && <p className="text-sm text-slate-500">Loading…</p>}

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
                                        Manage
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

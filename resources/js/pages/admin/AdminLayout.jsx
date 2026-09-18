import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

const NAV_ITEMS = [
    { to: '/admin/store-setup', label: 'Overview' },
    { to: '/admin/store-setup/fiscal-installations', label: 'Fiscal Installations' },
    { to: '/admin/store-setup/invoice-series', label: 'Invoice Series' },
    { to: '/admin/store-setup/inventory-locations', label: 'Inventory Locations' },
    { to: '/admin/store-setup/tax-registrations', label: 'Tax Registrations' },
];

/**
 * Shared dark "back-office" shell for the store-setup admin screens only
 * -- POS/login/dashboard keep their existing light theme. Visual
 * direction (slate surfaces, emerald accent, monospace for codes/counts)
 * follows the Stitch design reference the user pointed this pass at;
 * every actual FIELD shown on the pages themselves is grounded in the
 * real backend schema, not that mockup's invented fields (see
 * docs/06-ui/stage-8-store-setup.md).
 */
export default function AdminLayout({ children }) {
    const { user } = useAuth();
    const location = useLocation();

    if (!user.capabilities.includes('FISCAL_CONFIGURATION_MANAGE')) {
        return (
            <div className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100">
                <div className="mx-auto max-w-lg rounded-lg border border-slate-800 bg-slate-900 p-6">
                    <p className="text-sm text-red-400">Your role does not include store-setup configuration.</p>
                    <Link to="/" className="mt-4 inline-block text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <header className="border-b border-slate-800 bg-slate-900">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div>
                        <h1 className="text-sm font-semibold tracking-wide text-slate-100">Store Setup &amp; Fiscal Configuration</h1>
                        <p className="text-xs text-slate-500">{user.name} &middot; {user.role}</p>
                    </div>
                    <Link to="/" className="text-sm text-slate-400 underline hover:text-slate-200">
                        Dashboard
                    </Link>
                </div>
                <nav className="mx-auto flex max-w-5xl gap-1 px-4">
                    {NAV_ITEMS.map((item) => {
                        const active = location.pathname === item.to;
                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                className={`rounded-t-md border-b-2 px-3 py-2 text-xs font-medium ${
                                    active
                                        ? 'border-emerald-400 text-emerald-400'
                                        : 'border-transparent text-slate-400 hover:text-slate-200'
                                }`}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-6">{children}</main>
        </div>
    );
}

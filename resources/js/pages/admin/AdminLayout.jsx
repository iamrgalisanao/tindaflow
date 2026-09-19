import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

export const STORE_SETUP_NAV = [
    { to: '/admin/store-setup', label: 'Overview' },
    { to: '/admin/store-setup/fiscal-installations', label: 'Fiscal Installations' },
    { to: '/admin/store-setup/invoice-series', label: 'Invoice Series' },
    { to: '/admin/store-setup/inventory-locations', label: 'Inventory Locations' },
    { to: '/admin/store-setup/tax-registrations', label: 'Tax Registrations' },
];

/**
 * Shared dark "back-office" shell for the admin screens only -- POS/
 * login/dashboard keep their existing light theme. Visual direction
 * (slate surfaces, emerald accent, monospace for codes/counts) follows
 * the Stitch design reference the user pointed these passes at; every
 * actual FIELD shown on the pages themselves is grounded in the real
 * backend schema, not that mockup's invented fields (see
 * docs/06-ui/stage-8-store-setup.md, stage-11-reports-frontend.md).
 *
 * Defaults preserve the store-setup section; the Reports section passes
 * its own title/nav/capability. When the capability is missing, the
 * shell frame stays (so the user keeps their context) and `deniedView`
 * -- or a plain message -- replaces the page body.
 */
export default function AdminLayout({
    children,
    title = 'Store Setup & Fiscal Configuration',
    navItems = STORE_SETUP_NAV,
    requiredCapability = 'FISCAL_CONFIGURATION_MANAGE',
    deniedView = null,
    wide = false,
}) {
    const { user } = useAuth();
    const location = useLocation();
    const allowed = user.capabilities.includes(requiredCapability);

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <header className="border-b border-slate-800 bg-slate-900">
                <div className={`mx-auto flex items-center justify-between px-4 py-3 ${wide ? 'max-w-7xl' : 'max-w-5xl'}`}>
                    <div>
                        <h1 className="text-sm font-semibold tracking-wide text-slate-100">{title}</h1>
                        <p className="text-xs text-slate-500">{user.name} &middot; {user.role}</p>
                    </div>
                    <Link to="/" className="text-sm text-slate-400 underline hover:text-slate-200">
                        Dashboard
                    </Link>
                </div>
                {allowed && (
                    <nav className={`mx-auto flex gap-1 px-4 ${wide ? 'max-w-7xl' : 'max-w-5xl'}`}>
                        {navItems.map((item) => {
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
                )}
            </header>
            <main className={`mx-auto px-4 py-6 ${wide ? 'max-w-7xl' : 'max-w-5xl'}`}>
                {allowed ? (
                    children
                ) : (
                    deniedView ?? (
                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-6">
                            <p className="text-sm text-red-400">Your role does not include this section.</p>
                            <Link to="/" className="mt-4 inline-block text-sm text-slate-400 underline">
                                Back to dashboard
                            </Link>
                        </div>
                    )
                )}
            </main>
        </div>
    );
}

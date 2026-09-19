import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { REPORT_CATEGORIES, REPORTS } from './reports/reportsRegistry';

export const STORE_SETUP_NAV = [
    { to: '/admin/store-setup', label: 'Overview' },
    { to: '/admin/store-setup/fiscal-installations', label: 'Fiscal Installations' },
    { to: '/admin/store-setup/invoice-series', label: 'Invoice Series' },
    { to: '/admin/store-setup/inventory-locations', label: 'Inventory Locations' },
    { to: '/admin/store-setup/tax-registrations', label: 'Tax Registrations' },
];

const CATALOG_NAV = [
    { to: '/admin/catalog/products', label: 'Products' },
    { to: '/admin/catalog/categories', label: 'Categories' },
    { to: '/admin/catalog/brands', label: 'Brands' },
];

const INVENTORY_NAV = [
    { to: '/admin/inventory/stock', label: 'Stock' },
    { to: '/admin/inventory/movements', label: 'Movements' },
];

function Icon({ children }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className="shrink-0"
        >
            {children}
        </svg>
    );
}

const ICONS = {
    dashboard: (
        <Icon>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </Icon>
    ),
    reports: (
        <Icon>
            <path d="M3 20h18" />
            <rect x="5" y="11" width="3" height="9" />
            <rect x="10.5" y="5" width="3" height="15" />
            <rect x="16" y="14" width="3" height="6" />
        </Icon>
    ),
    users: (
        <Icon>
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20v-1a5.5 5.5 0 0 1 5.5-5.5h2A5.5 5.5 0 0 1 15.5 19v1" />
            <path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14a5.5 5.5 0 0 1 3 5v1" />
        </Icon>
    ),
    catalog: (
        <Icon>
            <path d="M21 8l-9-5-9 5v8l9 5 9-5V8z" />
            <path d="M3 8l9 5 9-5M12 13v8" />
        </Icon>
    ),
    inventory: (
        <Icon>
            <rect x="3" y="12" width="8" height="8" rx="1" />
            <rect x="13" y="12" width="8" height="8" rx="1" />
            <rect x="8" y="4" width="8" height="8" rx="1" />
        </Icon>
    ),
    store: (
        <Icon>
            <path d="M3 9l1.5-5h15L21 9" />
            <path d="M4 9v11h16V9" />
            <path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" />
            <path d="M10 20v-6h4v6" />
        </Icon>
    ),
    menu: (
        <Icon>
            <path d="M4 6h16M4 12h16M4 18h16" />
        </Icon>
    ),
    close: (
        <Icon>
            <path d="M6 6l12 12M18 6L6 18" />
        </Icon>
    ),
    signOut: (
        <Icon>
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="M16 17l5-5-5-5M21 12H9" />
        </Icon>
    ),
};

const REPORT_LINKS = [
    { to: '/admin/reports', label: 'All reports' },
    ...REPORT_CATEGORIES.flatMap((category) => [
        { heading: category.label },
        ...REPORTS.filter((report) => report.category === category.id).map((report) => ({
            to: `/admin/reports/${report.slug}`,
            label: report.title,
        })),
    ]),
];

const SECTIONS = [
    { id: 'dashboard', label: 'Dashboard', to: '/', icon: 'dashboard', matches: (path) => path === '/' },
    {
        id: 'reports',
        label: 'Reports',
        to: '/admin/reports',
        icon: 'reports',
        capability: 'REPORT_VIEW',
        matches: (path) => path.startsWith('/admin/reports'),
        links: REPORT_LINKS,
    },
    {
        id: 'catalog',
        label: 'Catalog',
        to: '/admin/catalog/products',
        icon: 'catalog',
        capability: 'CATALOG_MANAGE',
        matches: (path) => path.startsWith('/admin/catalog'),
        links: CATALOG_NAV,
    },
    {
        id: 'inventory',
        label: 'Inventory',
        to: '/admin/inventory/stock',
        icon: 'inventory',
        capability: 'STOCK_ADJUST',
        matches: (path) => path.startsWith('/admin/inventory'),
        links: INVENTORY_NAV,
    },
    {
        id: 'users',
        label: 'Users',
        to: '/admin/users',
        icon: 'users',
        capability: 'USER_MANAGE',
        matches: (path) => path.startsWith('/admin/users'),
    },
    {
        id: 'store-setup',
        label: 'Store Setup',
        to: '/admin/store-setup',
        icon: 'store',
        capability: 'FISCAL_CONFIGURATION_MANAGE',
        matches: (path) => path.startsWith('/admin/store-setup'),
        links: STORE_SETUP_NAV,
    },
];

const initials = (name) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/**
 * The navigation itself. `expanded` = show labels and sub-links regardless of width (the phone/tablet
 * drawer); otherwise labels and sub-links only appear from the lg breakpoint, leaving an icon rail
 * on tablets. Tap targets are 44px below lg, per the design system's touch rule.
 */
function SidebarBody({ expanded, user, sections, pathname, onNavigate, onSignOut, onClose, onOpenDrawer }) {
    const labelClass = expanded ? '' : 'hidden lg:inline';
    const blockClass = expanded ? '' : 'hidden lg:block';
    const row = 'flex min-h-11 items-center gap-3 rounded-md px-3 text-sm lg:min-h-9';

    return (
        <>
            <div className={`flex items-center gap-3 border-b border-slate-800 px-3 py-4 ${expanded ? '' : 'justify-center lg:justify-start'}`}>
                {onOpenDrawer && !expanded ? (
                    <button
                        type="button"
                        onClick={onOpenDrawer}
                        aria-label="Open navigation"
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-emerald-500 text-sm font-bold text-slate-950 hover:bg-emerald-400 lg:hidden"
                    >
                        TF
                    </button>
                ) : null}
                <span
                    aria-hidden="true"
                    className={`h-9 w-9 shrink-0 items-center justify-center rounded bg-emerald-500 text-sm font-bold text-slate-950 ${
                        expanded ? 'flex' : 'hidden lg:flex'
                    }`}
                >
                    TF
                </span>
                <div className={`min-w-0 flex-1 ${labelClass}`}>
                    <p className="text-sm font-bold tracking-wide text-slate-100">TINDAFLOW</p>
                    <p className="font-mono text-[10px] tracking-[0.2em] text-emerald-400">BACK OFFICE</p>
                </div>
                {onClose && (
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close navigation"
                        className="flex h-11 w-11 items-center justify-center rounded text-slate-400 hover:bg-slate-800 hover:text-slate-100"
                    >
                        {ICONS.close}
                    </button>
                )}
            </div>

            <nav aria-label="Back office" className="flex-1 space-y-1 overflow-y-auto px-2 py-3">
                {sections.map((section) => {
                    const active = section.matches(pathname);
                    return (
                        <div key={section.id}>
                            <Link
                                to={section.to}
                                onClick={onNavigate}
                                title={section.label}
                                aria-label={expanded ? undefined : section.label}
                                aria-current={active && pathname === section.to ? 'page' : undefined}
                                className={`${row} ${expanded ? '' : 'justify-center lg:justify-start'} ${
                                    active
                                        ? 'bg-emerald-950/60 text-emerald-300 ring-1 ring-emerald-800/60'
                                        : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100'
                                }`}
                            >
                                {ICONS[section.icon]}
                                <span className={`flex-1 ${labelClass}`}>{section.label}</span>
                                {active && <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full bg-emerald-400 ${labelClass}`} />}
                            </Link>
                            {active && section.links && (
                                <ul className={`mb-2 ml-5 mt-1 space-y-0.5 border-l border-slate-700 pl-2 ${blockClass}`}>
                                    {section.links.map((link) =>
                                        link.heading ? (
                                            <li
                                                key={link.heading}
                                                className="px-2 pb-0.5 pt-2 font-mono text-[10px] uppercase tracking-wider text-slate-600"
                                            >
                                                {link.heading}
                                            </li>
                                        ) : (
                                            <li key={link.to}>
                                                <Link
                                                    to={link.to}
                                                    onClick={onNavigate}
                                                    aria-current={pathname === link.to ? 'page' : undefined}
                                                    className={`flex min-h-11 items-center rounded px-2 text-[13px] lg:min-h-8 ${
                                                        pathname === link.to
                                                            ? 'bg-slate-800 font-medium text-emerald-300'
                                                            : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100'
                                                    }`}
                                                >
                                                    {link.label}
                                                </Link>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </div>
                    );
                })}
            </nav>

            <div className="border-t border-slate-800 p-2">
                <div className={`flex items-center gap-3 px-2 py-2 ${expanded ? '' : 'justify-center lg:justify-start'}`}>
                    <span
                        aria-hidden="true"
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-700 bg-slate-800 font-mono text-[11px] text-slate-300"
                    >
                        {initials(user.name)}
                    </span>
                    <div className={`min-w-0 ${labelClass}`}>
                        <p className="truncate text-sm text-slate-100">{user.name}</p>
                        <p className="truncate font-mono text-[11px] text-slate-500">{user.role}</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onSignOut}
                    title="Sign out"
                    aria-label="Sign out"
                    className={`${row} w-full text-slate-400 hover:bg-slate-800 hover:text-slate-100 ${expanded ? '' : 'justify-center lg:justify-start'}`}
                >
                    {ICONS.signOut}
                    <span className={labelClass}>Sign out</span>
                </button>
            </div>
        </>
    );
}

/**
 * Shared dark "back-office" shell for the admin screens only -- POS/login/dashboard keep their
 * existing light theme. Visual direction (slate surfaces, emerald accent, monospace for codes)
 * follows the Stitch design reference; every actual FIELD shown on the pages themselves is
 * grounded in the real backend schema, not that mockup's invented fields (see
 * docs/06-ui/stage-8-store-setup.md, stage-11-reports-frontend.md).
 *
 * Navigation follows the design system's responsive rules: a persistent sidebar from lg, an icon
 * rail on tablets (its "TF" button opens the full navigation), and a slide-in drawer on phones.
 * Sections the user has no capability for are not listed. When the page's own capability is
 * missing, the shell stays (so the user keeps their context) and `deniedView` -- or a plain
 * message -- replaces the page body.
 */
export default function AdminLayout({
    children,
    title = 'Store Setup & Fiscal Configuration',
    requiredCapability = 'FISCAL_CONFIGURATION_MANAGE',
    deniedView = null,
    wide = false,
}) {
    const { user, logout } = useAuth();
    const { pathname } = useLocation();
    const allowed = user.capabilities.includes(requiredCapability);
    const sections = SECTIONS.filter((section) => !section.capability || user.capabilities.includes(section.capability));

    const [drawerOpen, setDrawerOpen] = useState(false);
    const drawerRef = useRef(null);
    const closeRef = useRef(null);
    const openerRef = useRef(null);

    const closeDrawer = useCallback(() => setDrawerOpen(false), []);

    useEffect(() => {
        setDrawerOpen(false);
    }, [pathname]);

    useEffect(() => {
        if (!drawerOpen) {
            return undefined;
        }
        const opener = document.activeElement;
        openerRef.current = opener;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        closeRef.current?.querySelector('button')?.focus();

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setDrawerOpen(false);
                return;
            }
            if (event.key !== 'Tab' || !drawerRef.current) {
                return;
            }
            const focusable = drawerRef.current.querySelectorAll('a[href], button:not([disabled])');
            if (focusable.length === 0) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
            opener?.focus?.();
        };
    }, [drawerOpen]);

    const shared = { user, sections, pathname, onSignOut: logout };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 md:flex">
            <aside className="sticky top-0 hidden h-screen shrink-0 flex-col border-r border-slate-800 bg-slate-900 md:flex md:w-16 lg:w-64">
                <SidebarBody {...shared} expanded={false} onOpenDrawer={() => setDrawerOpen(true)} />
            </aside>

            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
                    <div className="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" onClick={closeDrawer} aria-hidden="true" />
                    <div
                        ref={drawerRef}
                        className="absolute inset-y-0 left-0 flex w-[85%] max-w-xs flex-col border-r border-slate-700 bg-slate-900 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
                    >
                        <div ref={closeRef} className="contents">
                            <SidebarBody {...shared} expanded onNavigate={closeDrawer} onClose={closeDrawer} />
                        </div>
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-slate-800 bg-slate-950 px-2 py-2 md:px-4">
                    <button
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        aria-label="Open navigation"
                        className="flex h-11 w-11 items-center justify-center rounded text-slate-300 hover:bg-slate-800 md:hidden"
                    >
                        {ICONS.menu}
                    </button>
                    <p className="min-w-0 flex-1 truncate font-mono text-xs uppercase tracking-wider text-slate-500">
                        Back Office <span className="text-slate-700">/</span> <span className="text-emerald-400">{title}</span>
                    </p>
                    <span
                        aria-hidden="true"
                        className="flex h-8 w-8 items-center justify-center rounded-full border border-emerald-800 bg-emerald-950 font-mono text-[11px] text-emerald-300 md:hidden"
                    >
                        {initials(user.name)}
                    </span>
                </header>
                <main className={`mx-auto w-full px-4 py-6 ${wide ? 'max-w-7xl' : 'max-w-5xl'}`}>
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
        </div>
    );
}

import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import AdminLayout from './admin/AdminLayout';
import LicenseNotice from './admin/license/LicenseNotice';
import { ErrorAlert } from './admin/catalog/CatalogParts';
import { failureMessage } from './admin/catalog/catalogApi';
import { useLoad } from './admin/records/historyParts';
import DashboardSales from './DashboardSales';
import { SummaryCards } from './admin/reports/ReportParts';

/**
 * The authenticated landing page (docs/06-ui/sitemap.md /back-office/dashboard), kept as a route
 * distinct from Reports > Daily Sales Summary -- the sitemap's Stage 7 validation pass settled that
 * against UTAK/StoreHub reference research: an at-a-glance figure a manager reads without running
 * anything, not a report.
 *
 * Every figure here is read from an endpoint the actor's own capabilities already allow, and each
 * block is skipped entirely when they hold no capability for it -- a cashier sees the till and their
 * own sales history, never an empty metric grid. Nothing is computed client-side beyond adding the
 * two pending-approval counts together and the sales block's count of transactions.
 *
 * The sales figures follow a chosen date range ({@see DashboardSales}); "Needs attention" is always
 * what is waiting now.
 */
export default function Dashboard() {
    const { user } = useAuth();
    const holds = (capability) => user.capabilities.includes(capability);

    const canReport = holds('REPORT_VIEW');
    const canApprove = holds('SALE_VOID_APPROVE') || holds('SALE_REFUND_APPROVE');
    const canStock = holds('STOCK_ADJUST');

    const voids = useLoad(canApprove ? '/api/v1/voids?status=REQUESTED&per_page=1' : null);
    const refunds = useLoad(canApprove ? '/api/v1/refunds?status=REQUESTED&per_page=1' : null);
    const lowStock = useLoad(canStock ? '/api/v1/inventory/low-stock?per_page=1' : null);

    const pendingVoids = voids.data?.meta?.total ?? null;
    const pendingRefunds = refunds.data?.meta?.total ?? null;
    const pendingApprovals = pendingVoids === null && pendingRefunds === null ? null : (pendingVoids ?? 0) + (pendingRefunds ?? 0);
    const lowStockCount = lowStock.data?.meta?.total ?? null;

    const attention = [
        canApprove && pendingApprovals !== null
            ? {
                  label: 'Void / refund approvals',
                  node: <Link to="/admin/sales/approvals" className="hover:underline">{pendingApprovals.toLocaleString('en-US')} waiting</Link>,
                  sub: pendingApprovals === 0 ? 'nothing to decide' : 'requested, not yet decided',
                  tone: pendingApprovals > 0 ? 'amber' : undefined,
              }
            : null,
        canStock && lowStockCount !== null
            ? {
                  label: 'Below reorder level',
                  // The card is gated on STOCK_ADJUST, so it must not lead somewhere that needs a capability
                  // the holder might not have: the Low Stock report is REPORT_VIEW, the stock screen is not.
                  node: (
                      <Link to={canReport ? '/admin/reports/low-stock' : '/admin/inventory/stock'} className="hover:underline">
                          {lowStockCount.toLocaleString('en-US')} products
                      </Link>
                  ),
                  sub: lowStockCount === 0 ? 'every product is stocked' : 'across all locations',
                  tone: lowStockCount > 0 ? 'rose' : undefined,
              }
            : null,
    ].filter(Boolean);

    const shortcuts = [
        { to: '/admin/sales', label: 'Sales history', note: 'Look up a transaction, void or refund it', capability: 'SALE_VOID' },
        { to: '/admin/reports', label: 'Reports', note: 'All 20 reports, exportable as CSV', capability: 'REPORT_VIEW' },
        { to: '/admin/catalog/products', label: 'Catalog', note: 'Products, categories, brands, barcodes', capability: 'CATALOG_MANAGE' },
        { to: '/admin/inventory/stock', label: 'Inventory', note: 'Stock on hand, counts, transfers', capability: 'STOCK_ADJUST' },
        { to: '/admin/shifts', label: 'Shifts', note: 'Drawer counts and variances', capability: 'REPORT_VIEW' },
        { to: '/admin/audit', label: 'Audit log', note: 'Who did what, and when', capability: 'AUDIT_VIEW' },
        { to: '/admin/employees', label: 'Employees', note: 'Credit employees, ID cards, credit limits', capability: 'EMPLOYEE_MANAGE' },
        { to: '/admin/users', label: 'Users', note: 'Cashiers, managers, admins', capability: 'USER_MANAGE' },
        { to: '/admin/license', label: 'License', note: 'Status, check now, enter a code', capability: 'LICENSE_MANAGE' },
        { to: '/admin/terminals', label: 'Terminals', note: 'Enrol a till, review enrolled terminals', capability: 'TERMINAL_MANAGE' },
        { to: '/admin/store-setup', label: 'Store setup', note: 'Business details, stock locations, fiscal setup and readiness', capability: 'STORE_SETTINGS_MANAGE' },
        { to: '/help', label: 'Help & guides', note: 'Step-by-step guides with pictures', capability: null },
    ].filter((shortcut) => shortcut.capability === null || holds(shortcut.capability));

    return (
        <AdminLayout title="Dashboard" requiredCapability={null} wide>
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Dashboard</h2>
                    <p className="mt-1 text-sm text-slate-400">
                        {new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                        <span className="text-slate-600"> · </span>
                        <span className="font-mono text-xs uppercase tracking-wider text-slate-500">{user.role}</span>
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        to="/pos"
                        className="flex min-h-11 items-center rounded-md bg-emerald-500 px-5 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400"
                    >
                        Open the till
                    </Link>
                </div>
            </div>

            <LicenseNotice allowed={holds('LICENSE_MANAGE')} />

            {canReport && <DashboardSales />}

            {attention.length > 0 && (
                <section className="mb-2" aria-label="Needs attention">
                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-500">Needs attention</h3>
                    {voids.failure && (
                        <ErrorAlert message={failureMessage(voids.failure, 'The pending approvals could not be counted.')} onRetry={voids.reload} />
                    )}
                    {lowStock.failure && (
                        <ErrorAlert message={failureMessage(lowStock.failure, 'The low-stock count could not be loaded.')} onRetry={lowStock.reload} />
                    )}
                    <SummaryCards items={attention} />
                </section>
            )}

            {!canReport && !canStock && (
                <section className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
                    <h3 className="text-sm font-semibold text-slate-100">Your shift starts at the till</h3>
                    <p className="mt-1 max-w-prose text-sm text-slate-400">
                        Open the till to start selling. You can look up an earlier transaction, reprint its invoice or slip, or ask for a void or refund from
                        Sales history — a manager decides those.
                    </p>
                    <Link to="/help" className="mt-2 inline-block text-sm text-emerald-400 hover:underline">
                        New here? Read the step-by-step guides →
                    </Link>
                </section>
            )}

            {shortcuts.length > 0 && (
                <section aria-label="Jump to">
                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-500">Jump to</h3>
                    <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {shortcuts.map((shortcut) => (
                            <li key={shortcut.to}>
                                <Link
                                    to={shortcut.to}
                                    className="flex min-h-11 flex-col justify-center rounded-lg border border-slate-800 bg-slate-900 px-4 py-3 hover:border-slate-600 hover:bg-slate-800"
                                >
                                    <span className="text-sm font-medium text-slate-100">{shortcut.label}</span>
                                    <span className="text-xs text-slate-500">{shortcut.note}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AdminLayout>
    );
}

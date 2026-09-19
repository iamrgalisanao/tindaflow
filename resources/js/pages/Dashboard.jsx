import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * Authenticated landing page. Back-office mode (docs/06-ui/sitemap.md)
 * beyond terminal management is not built yet -- most of it is blocked
 * on backend endpoints that don't exist (see
 * stage-7-frontend-initialization.md §2). POS mode is now real.
 */
export default function Dashboard() {
    const { user, logout } = useAuth();

    return (
        <div className="min-h-screen bg-gray-50 px-4 py-8">
            <div className="mx-auto max-w-lg space-y-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold text-gray-900">TindaFlow</h1>
                    <button
                        type="button"
                        onClick={logout}
                        className="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Sign out
                    </button>
                </div>

                <dl className="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
                    <dt className="text-gray-500">Name</dt>
                    <dd className="col-span-2 text-gray-900">{user.name}</dd>
                    <dt className="text-gray-500">Email</dt>
                    <dd className="col-span-2 text-gray-900">{user.email}</dd>
                    <dt className="text-gray-500">Role</dt>
                    <dd className="col-span-2 text-gray-900">{user.role}</dd>
                </dl>

                <div>
                    <p className="mb-1 text-sm font-medium text-gray-700">Capabilities</p>
                    <div className="flex flex-wrap gap-1.5">
                        {user.capabilities.map((capability) => (
                            <span
                                key={capability}
                                className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700"
                            >
                                {capability}
                            </span>
                        ))}
                    </div>
                </div>

                <div className="flex gap-2">
                    <Link
                        to="/pos"
                        className="flex-1 rounded-md bg-gray-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-gray-800"
                    >
                        Open POS
                    </Link>
                    {user.capabilities.includes('TERMINAL_MANAGE') && (
                        <Link
                            to="/terminals"
                            className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-center text-sm hover:bg-gray-50"
                        >
                            Terminal enrollment
                        </Link>
                    )}
                    {user.capabilities.includes('FISCAL_CONFIGURATION_MANAGE') && (
                        <Link
                            to="/admin/store-setup"
                            className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-center text-sm hover:bg-gray-50"
                        >
                            Store setup
                        </Link>
                    )}
                    {user.capabilities.includes('REPORT_VIEW') && (
                        <Link
                            to="/admin/reports"
                            className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-center text-sm hover:bg-gray-50"
                        >
                            Reports
                        </Link>
                    )}
                </div>

                <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Back-office screens beyond terminal management, store setup, and
                    reports (products, users) are not built yet — their backend
                    endpoints don't exist yet.
                </p>
            </div>
        </div>
    );
}

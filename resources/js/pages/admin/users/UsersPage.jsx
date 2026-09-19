import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ActiveBadge, ConfirmDialog, ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fetchAll, request } from '../catalog/catalogApi';
import UserFormPanel from './UserFormPanel';

const PER_PAGE = 25;
const ROLE_FILTERS = [
    { id: '', label: 'All roles' },
    { id: 'ADMIN', label: 'Admin' },
    { id: 'MANAGER', label: 'Manager' },
    { id: 'CASHIER', label: 'Cashier' },
];
const STATUSES = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active' },
    { id: 'inactive', label: 'Inactive' },
];
const ROLE_BADGE = {
    ADMIN: 'border-emerald-700/70 text-emerald-400',
    MANAGER: 'border-sky-700/70 text-sky-300',
    CASHIER: 'border-slate-600 text-slate-300',
};

function RoleBadge({ role }) {
    return <span className={`inline-block rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${ROLE_BADGE[role]}`}>{role}</span>;
}

/**
 * /admin/users -- userList (server-side filter + pagination), userCreate/Update in a slide-over,
 * userDeactivate/Activate. Users are never deleted. The screen will not offer to deactivate you or
 * the only active administrator, nor to change either one's role (a UI guard; see UserFormPanel).
 */
export default function UsersPage() {
    const { user: me, setUser } = useAuth();
    const [role, setRole] = useState('');
    const [status, setStatus] = useState('all');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [activeAdminIds, setActiveAdminIds] = useState([]);
    const [panel, setPanel] = useState(null); // null | { user?: object }
    const [confirm, setConfirm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanel = useCallback(() => setPanel(null), []);

    useEffect(() => {
        const current = ++seq.current;
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (role) {
            params.set('role', role);
        }
        if (status !== 'all') {
            params.set('active', status === 'active' ? '1' : '0');
        }
        setLoading(true);
        setFailure(null);
        request(`/api/v1/users?${params.toString()}`).then((response) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setResult(response.body);
            } else {
                setResult(null);
                setFailure(response);
            }
        });
        fetchAll('/api/v1/users?role=ADMIN&active=1').then((admins) => admins.ok && setActiveAdminIds(admins.rows.map((admin) => admin.id)));
    }, [role, status, page, reloadKey]);

    function reload() {
        setReloadKey((key) => key + 1);
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    async function setActive(target, active) {
        setBusy(true);
        const response = await request(`/api/v1/users/${target.id}/${active ? 'activate' : 'deactivate'}`, { method: 'POST' });
        setBusy(false);
        setConfirm(null);
        if (response.status === 401) {
            signIn();
            return;
        }
        if (!response.ok) {
            setFailure(response);
            return;
        }
        setPanel(null);
        setToast(active ? `${target.name} reactivated` : `${target.name} deactivated`);
        reload();
    }

    function saved(_saved, wasEditing) {
        setPanel(null);
        setToast(wasEditing ? 'User saved' : 'User created');
        reload();
    }

    const isSelf = (target) => target.id === me.id;
    const isLastAdmin = (target) => target.role === 'ADMIN' && target.active && activeAdminIds.length === 1 && activeAdminIds[0] === target.id;
    const users = result?.data ?? [];

    const rowActions = (target) => {
        const protectedFromDeactivation = isSelf(target) || isLastAdmin(target);
        return (
            <div className="flex items-center justify-end gap-3 whitespace-nowrap text-xs">
                <button type="button" onClick={() => setPanel({ user: target })} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                    Edit
                </button>
                {target.active ? (
                    <button
                        type="button"
                        disabled={protectedFromDeactivation}
                        title={protectedFromDeactivation ? (isSelf(target) ? "You can't deactivate your own account." : "The only active administrator can't be deactivated.") : undefined}
                        onClick={() => setConfirm(target)}
                        className="min-h-11 text-slate-400 hover:text-rose-400 hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:text-slate-400 disabled:hover:no-underline lg:min-h-0"
                    >
                        Deactivate
                    </button>
                ) : (
                    <button type="button" onClick={() => setActive(target, true)} className="min-h-11 text-slate-400 hover:text-emerald-400 hover:underline lg:min-h-0">
                        Activate
                    </button>
                )}
            </div>
        );
    };

    return (
        <AdminLayout title="Users" requiredCapability="USER_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Users</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        The people who sign in to TindaFlow. Users are deactivated, never deleted, because past sales and records refer to them.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setPanel({})}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                >
                    New user
                </button>
            </div>

            <div className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:items-end">
                <div>
                    <label htmlFor="user_role_filter" className="mb-1 block text-xs text-slate-500">
                        Role
                    </label>
                    <select
                        id="user_role_filter"
                        value={role}
                        onChange={(event) => {
                            setRole(event.target.value);
                            setPage(1);
                        }}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 md:w-44 lg:min-h-0"
                    >
                        {ROLE_FILTERS.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div role="group" aria-label="Status">
                    <span className="mb-1 block text-xs text-slate-500">Status</span>
                    <div className="flex overflow-hidden rounded-md border border-slate-700">
                        {STATUSES.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                aria-pressed={status === option.id}
                                onClick={() => {
                                    setStatus(option.id);
                                    setPage(1);
                                }}
                                className={`min-h-11 flex-1 px-3 py-1.5 text-xs md:flex-none lg:min-h-0 ${
                                    status === option.id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The users could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading users">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && users.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">No users match these filters.</p>
                    <button
                        type="button"
                        onClick={() => {
                            setRole('');
                            setStatus('all');
                            setPage(1);
                        }}
                        className="mt-2 text-xs text-emerald-400 underline"
                    >
                        Clear filters
                    </button>
                </div>
            )}

            {result && users.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Name', 'Email', 'Role', 'Access', 'Status', ''].map((heading) => (
                                        <th key={heading || 'actions'} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {users.map((target) => (
                                    <tr key={target.id} className={`odd:bg-slate-950/40 hover:bg-slate-900 ${target.active ? '' : 'text-slate-500'}`}>
                                        <td className="px-3 py-2">
                                            <span className={target.active ? 'text-slate-100' : 'text-slate-500'}>{target.name}</span>
                                            {isSelf(target) && (
                                                <span className="ml-2 rounded border border-emerald-800 px-1 py-0.5 font-mono text-[10px] text-emerald-400">YOU</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-300">{target.email}</td>
                                        <td className="px-3 py-2">
                                            <RoleBadge role={target.role} />
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400" title={target.capabilities.join(', ')}>
                                            {target.capabilities.length} capabilities
                                        </td>
                                        <td className="px-3 py-2">
                                            <ActiveBadge active={target.active} />
                                        </td>
                                        <td className="px-3 py-2">{rowActions(target)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Users">
                        {users.map((target) => (
                            <li key={target.id} className={`rounded-lg border border-slate-800 bg-slate-900 p-3 ${target.active ? '' : 'opacity-70'}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="break-words text-sm text-slate-100">
                                            {target.name}
                                            {isSelf(target) && <span className="ml-2 rounded border border-emerald-800 px-1 py-0.5 font-mono text-[10px] text-emerald-400">YOU</span>}
                                        </p>
                                        <p className="break-all font-mono text-[11px] text-slate-400">{target.email}</p>
                                    </div>
                                    <ActiveBadge active={target.active} />
                                </div>
                                <div className="mt-2 flex items-center justify-between text-sm text-slate-400">
                                    <RoleBadge role={target.role} />
                                    <span className="font-mono text-[12px]">{target.capabilities.length} capabilities</span>
                                </div>
                                <div className="mt-1 border-t border-slate-800 pt-1">{rowActions(target)}</div>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {panel && (
                <UserFormPanel
                    key={panel.user?.id ?? 'new'}
                    user={panel.user}
                    isSelf={panel.user ? isSelf(panel.user) : false}
                    isLastAdmin={panel.user ? isLastAdmin(panel.user) : false}
                    onSaved={saved}
                    onRequestDeactivate={(target) => setConfirm(target)}
                    onActivate={(target) => setActive(target, true)}
                    onClose={closePanel}
                    onUnauthorized={signIn}
                />
            )}

            {confirm && (
                <ConfirmDialog
                    title={`Deactivate ${confirm.name}?`}
                    body="They are signed out on their next request and cannot sign in until you reactivate them. Their past sales and records stay as they are."
                    confirmLabel="Deactivate"
                    busy={busy}
                    onConfirm={() => setActive(confirm, false)}
                    onCancel={() => setConfirm(null)}
                />
            )}
        </AdminLayout>
    );
}

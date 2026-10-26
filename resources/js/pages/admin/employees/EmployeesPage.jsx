import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ActiveBadge, ConfirmDialog, ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import CreditLimitsPanel from './CreditLimitsPanel';
import EmployeeFormPanel from './EmployeeFormPanel';
import EmployeeImportPanel from './EmployeeImportPanel';
import { EMPLOYEE_TYPES, employeeQuery, typeShort } from './employeeConfig';

const PER_PAGE = 25;
const STATUSES = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active' },
    { id: 'inactive', label: 'Inactive' },
];

/**
 * /admin/employees -- employeeList (server-side search, filter and pagination), employeeCreate / Update in a slide-over,
 * deactivate / reactivate, the credit limit per type and the CSV import. Employees are deactivated, never deleted, and their code
 * (made by the system) never changes or comes back.
 */
export default function EmployeesPage() {
    const { setUser, user } = useAuth();
    const canManagePolicy = user.capabilities.includes('EMPLOYEE_POLICY_MANAGE'); // ADMIN only: credit limits and the CSV import
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('all');
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [panel, setPanel] = useState(null); // null | { employee?: object } | { importing: true }
    const [confirm, setConfirm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanel = useCallback(() => setPanel(null), []);

    useEffect(() => {
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        request(`/api/v1/employees?${employeeQuery({ search: term, type, status, page, perPage: PER_PAGE })}`).then((response) => {
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
    }, [term, type, status, page, reloadKey]);

    const reload = useCallback(() => setReloadKey((key) => key + 1), []);

    const signIn = useCallback(() => {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }, [setUser]);

    async function setActive(target, active) {
        setBusy(true);
        const response = await request(`/api/v1/employees/${target.id}/${active ? 'activate' : 'deactivate'}`, { method: 'POST' });
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

    function saved(employee, wasEditing, options = {}) {
        if (!options.keepOpen) {
            setPanel(null);
        }
        setToast(wasEditing ? 'Employee saved' : `Employee created: ${employee.employee_code}`);
        reload();
    }

    function submitSearch(event) {
        event.preventDefault();
        setTerm(search);
        setPage(1);
    }

    const employees = result?.data ?? [];

    const rowActions = (target) => (
        <div className="flex items-center justify-end gap-3 whitespace-nowrap text-xs">
            <button type="button" onClick={() => setPanel({ employee: target })} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                Edit
            </button>
            {target.active ? (
                <button type="button" onClick={() => setConfirm(target)} className="min-h-11 text-slate-400 hover:text-rose-400 hover:underline lg:min-h-0">
                    Deactivate
                </button>
            ) : (
                <button type="button" onClick={() => setActive(target, true)} className="min-h-11 text-slate-400 hover:text-emerald-400 hover:underline lg:min-h-0">
                    Reactivate
                </button>
            )}
        </div>
    );

    return (
        <AdminLayout title="Employees" requiredCapability="EMPLOYEE_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Employees</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        The people who can be charged on credit. Each gets a code made by the system (TSC00001 for direct employees, SUB00001 for subcontractors) that never
                        changes and is never given to anyone else. Employees are deactivated, never deleted.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link to="/admin/employees/cards" className="flex min-h-11 items-center rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Print ID cards
                    </Link>
                    {canManagePolicy && (
                        <button type="button" onClick={() => setPanel({ importing: true })} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                            Import CSV
                        </button>
                    )}
                    <button type="button" onClick={() => setPanel({})} className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0">
                        New employee
                    </button>
                </div>
            </div>

            <CreditLimitsPanel readOnly={!canManagePolicy} onUnauthorized={signIn} onSaved={setToast} />

            <div className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:items-end">
                <form onSubmit={submitSearch} role="search" className="flex flex-1 items-end gap-2">
                    <div className="min-w-0 flex-1">
                        <label htmlFor="employee_search" className="mb-1 block text-xs text-slate-500">
                            Search by name or code
                        </label>
                        <input
                            id="employee_search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0"
                        />
                    </div>
                    <button type="submit" className="min-h-11 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Search
                    </button>
                </form>
                <div>
                    <label htmlFor="employee_type_filter" className="mb-1 block text-xs text-slate-500">
                        Type
                    </label>
                    <select
                        id="employee_type_filter"
                        value={type}
                        onChange={(event) => {
                            setType(event.target.value);
                            setPage(1);
                        }}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 md:w-48 lg:min-h-0"
                    >
                        <option value="">All types</option>
                        {EMPLOYEE_TYPES.map((entry) => (
                            <option key={entry.id} value={entry.id}>
                                {entry.label}
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
                                className={`min-h-11 flex-1 px-3 py-1.5 text-xs md:flex-none lg:min-h-0 ${status === option.id ? 'bg-emerald-950/60 text-emerald-400' : 'text-slate-400 hover:bg-slate-800'}`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The employees could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : reload}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading employees">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && employees.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">{term || type || status !== 'all' ? 'No employees match these filters.' : 'No employees yet. Add one, or import a CSV.'}</p>
                    {(term || type || status !== 'all') && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setTerm('');
                                setType('');
                                setStatus('all');
                                setPage(1);
                            }}
                            className="mt-2 text-xs text-emerald-400 underline"
                        >
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {result && employees.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['Code', 'Name', 'Type', 'Status', ''].map((heading) => (
                                        <th key={heading || 'actions'} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {employees.map((target) => (
                                    <tr key={target.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="px-3 py-2 font-mono text-[12px] text-emerald-300">{target.employee_code}</td>
                                        <td className={`px-3 py-2 ${target.active ? 'text-slate-100' : 'text-slate-500'}`}>{target.name}</td>
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-300">{typeShort(target.employee_type)}</td>
                                        <td className="px-3 py-2">
                                            <ActiveBadge active={target.active} />
                                        </td>
                                        <td className="px-3 py-2">{rowActions(target)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Employees">
                        {employees.map((target) => (
                            <li key={target.id} className={`rounded-lg border border-slate-800 bg-slate-900 p-3 ${target.active ? '' : 'opacity-70'}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="break-words text-sm text-slate-100">{target.name}</p>
                                        <p className="font-mono text-[12px] text-emerald-300">
                                            {target.employee_code} <span className="text-slate-500">&middot; {typeShort(target.employee_type)}</span>
                                        </p>
                                    </div>
                                    <ActiveBadge active={target.active} />
                                </div>
                                <div className="mt-1 border-t border-slate-800 pt-1">{rowActions(target)}</div>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {canManagePolicy && panel?.importing && <EmployeeImportPanel onClose={closePanel} onImported={reload} onUnauthorized={signIn} />}
            {panel && !panel.importing && <EmployeeFormPanel key={panel.employee?.id ?? 'new'} employee={panel.employee} onSaved={saved} onClose={closePanel} onUnauthorized={signIn} />}

            {confirm && (
                <ConfirmDialog
                    title={`Deactivate ${confirm.name}?`}
                    body={`${confirm.employee_code} will no longer be accepted at the till. Their code is kept and is never given to anyone else. You can reactivate them later.`}
                    confirmLabel="Deactivate"
                    busy={busy}
                    onConfirm={() => setActive(confirm, false)}
                    onCancel={() => setConfirm(null)}
                />
            )}
        </AdminLayout>
    );
}

import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { usePrintFrame } from '../../../lib/usePrintFrame';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';
import { downloadCsv } from './billingApi';
import { formatWhen, printDocument } from './billingConfig';

const PER_PAGE = 25;

/** /admin/billing/runs -- the saved billing runs, newest first, with open, reprint and CSV. A run is never edited; a re-run is a new number. */
export default function BillingRunsPage() {
    const { setUser } = useAuth();
    const [page, setPage] = useState(1);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [toast, setToast] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const { print, frame } = usePrintFrame();
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(() => {
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        request(`/api/v1/billing-runs?per_page=${PER_PAGE}&page=${page}`).then((response) => {
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
    }, [page]);

    useEffect(() => {
        load();
    }, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    async function reprint(run) {
        setBusyId(run.id);
        const response = await request(`/api/v1/billing-runs/${run.id}`);
        setBusyId(null);
        if (!response.ok) {
            setFailure(response);
            return;
        }
        print(printDocument(response.body.data.statement, response.body.data), 'Billing run to print');
    }

    async function exportCsv(run) {
        setBusyId(run.id);
        const outcome = await downloadCsv(`/api/v1/billing-runs/${run.id}`, `billing_${run.run_number}.csv`);
        setBusyId(null);
        if (outcome.ok) {
            setToast(`Exported ${outcome.filename}`);
        } else {
            setFailure({ status: outcome.status ?? 0, body: null, message: outcome.message });
        }
    }

    const runs = result?.data ?? [];
    const failureText = failure ? (failure.message ?? failureMessage(failure, 'The billing runs could not be loaded.')) : null;
    const button = 'min-h-11 text-emerald-400 hover:underline disabled:opacity-50 lg:min-h-0';

    return (
        <AdminLayout title="Billing runs" requiredCapability="BILLING_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            {frame}
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Billing runs</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        The statements that were saved, exactly as they were when saved. A run is never changed; saving the same period again makes a new run and shows what is different.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link to="/admin/billing/cheques" className="flex min-h-11 items-center self-start rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Cheques and balance
                    </Link>
                    <Link to="/admin/billing" className="flex min-h-11 items-center self-start rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0">
                        New statement
                    </Link>
                </div>
            </div>

            {failureText && <ErrorAlert message={failureText} onSignIn={failure.status === 401 ? signIn : undefined} onRetry={failure.status === 401 ? undefined : load} />}

            <div className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-500">
                        <tr>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Run</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Period</th>
                            <th scope="col" className="px-3 py-2 text-left font-normal">Saved</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Employees</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Total due</th>
                            <th scope="col" className="px-3 py-2 text-right font-normal">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {runs.map((run) => (
                            <tr key={run.id} className="border-t border-slate-800 text-slate-200">
                                <td className="px-3 py-2 font-mono text-emerald-300">{run.run_number}</td>
                                <td className="px-3 py-2">
                                    {run.period_label}
                                    <span className="block font-mono text-xs text-slate-500">
                                        {run.from} to {run.to}
                                        {run.employee_type ? ` · ${run.employee_type === 'SUBCONTRACTOR' ? 'SUB' : 'TSC'} only` : ''}
                                        {run.employee_code ? ` · ${run.employee_code}` : ''}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {formatWhen(run.generated_at)}
                                    <span className="block text-slate-500">{run.generated_by?.name ?? ''}</span>
                                </td>
                                <td className="px-3 py-2 text-right font-mono tabular-nums">{run.employee_count}</td>
                                <td className="px-3 py-2 text-right font-mono tabular-nums">{formatMoney(run.net_amount)}</td>
                                <td className="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    <span className="flex items-center justify-end gap-3">
                                        <Link to={`/admin/billing/runs/${run.id}`} className="min-h-11 content-center text-emerald-400 hover:underline lg:min-h-0" aria-label={`Open ${run.run_number}`}>
                                            Open
                                        </Link>
                                        <button type="button" onClick={() => reprint(run)} disabled={busyId === run.id} className={button} aria-label={`Print ${run.run_number}`}>
                                            Print
                                        </button>
                                        <button type="button" onClick={() => exportCsv(run)} disabled={busyId === run.id} className={button} aria-label={`Export ${run.run_number} as CSV`}>
                                            CSV
                                        </button>
                                    </span>
                                </td>
                            </tr>
                        ))}
                        {!loading && runs.length === 0 && !failure && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-slate-400">
                                    No billing runs have been saved yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pager meta={result?.meta} onPage={setPage} />
        </AdminLayout>
    );
}

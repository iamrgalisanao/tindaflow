import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { usePrintFrame } from '../../../lib/usePrintFrame';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { downloadCsv } from './billingApi';
import { formatWhen, printDocument } from './billingConfig';
import StatementView from './StatementView';

/** /admin/billing/runs/:runId -- one saved run, exactly as saved, with print and CSV. */
export default function BillingRunPage() {
    const { runId } = useParams();
    const { setUser } = useAuth();
    const [run, setRun] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [toast, setToast] = useState(null);
    const { print, frame } = usePrintFrame();
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(() => {
        setLoading(true);
        setFailure(null);
        request(`/api/v1/billing-runs/${runId}`).then((response) => {
            setLoading(false);
            if (response.ok) {
                setRun(response.body.data);
            } else {
                setRun(null);
                setFailure(response);
            }
        });
    }, [runId]);

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

    async function exportCsv() {
        const outcome = await downloadCsv(`/api/v1/billing-runs/${run.id}`, `billing_${run.run_number}.csv`);
        if (outcome.ok) {
            setToast(`Exported ${outcome.filename}`);
        } else {
            setFailure({ status: outcome.status ?? 0, body: null, message: outcome.message });
        }
    }

    const failureText = failure ? (failure.status === 404 ? 'That billing run was not found.' : (failure.message ?? failureMessage(failure, 'The billing run could not be loaded.'))) : null;

    return (
        <AdminLayout title={run ? `Billing run ${run.run_number}` : 'Billing run'} requiredCapability="BILLING_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            {frame}
            <p className="mb-3 text-sm">
                <Link to="/admin/billing/runs" className="text-slate-400 hover:text-slate-200 hover:underline">
                    &larr; All billing runs
                </Link>
            </p>
            {failureText && <ErrorAlert message={failureText} onSignIn={failure.status === 401 ? signIn : undefined} onRetry={[401, 404].includes(failure.status) ? undefined : load} />}
            {loading && !run && <p className="text-sm text-slate-400">Loading…</p>}
            {run && (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <div className="mr-auto">
                            <h2 className="font-mono text-xl font-semibold text-emerald-300">{run.run_number}</h2>
                            <p className="text-sm text-slate-400">
                                Saved {formatWhen(run.generated_at)} by {run.generated_by?.name ?? 'a user'}. This is the statement exactly as it was then; it never changes.
                            </p>
                            {!run.statement_intact && (
                                <p role="alert" className="mt-1 text-sm font-semibold text-rose-400">
                                    Warning: this saved statement no longer matches the fingerprint recorded when it was saved. Do not use it; tell an administrator.
                                </p>
                            )}
                        </div>
                        <button type="button" onClick={() => print(printDocument(run.statement, run), 'Billing run to print')} className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0">
                            Print
                        </button>
                        <button type="button" onClick={exportCsv} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                            Export CSV
                        </button>
                    </div>
                    <StatementView statement={run.statement} />
                </>
            )}
        </AdminLayout>
    );
}

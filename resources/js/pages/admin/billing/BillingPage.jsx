import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import { usePrintFrame } from '../../../lib/usePrintFrame';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fetchAll, request } from '../catalog/catalogApi';
import { EMPLOYEE_TYPES } from '../employees/employeeConfig';
import { downloadCsv } from './billingApi';
import { HALVES, csvFileName, currentCutoff, cutoffRange, printDocument, statementParams, toQuery, validateRange } from './billingConfig';
import StatementView from './StatementView';

const FIELD = 'mt-1 block min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none lg:min-h-0';
const LABEL = 'block text-xs font-medium text-slate-400';

function initialForm() {
    const { month, half } = currentCutoff();
    return { mode: 'cutoff', month, half, from: '', to: '', employeeType: '', employeeText: '', employeeId: '', asOf: '' };
}

/**
 * /admin/billing -- the billing generator (ADR-016 section 8, slice 4.1). Pick a cut-off (or any dates), optionally one employee type or one
 * employee, press Generate to read the statement from the credit ledger, then print it, export it as CSV or save it as a numbered billing
 * run. Generating changes nothing; only "Save as billing run" writes, and every save is a new run.
 */
export default function BillingPage() {
    const { setUser } = useAuth();
    const [form, setForm] = useState(initialForm);
    const [errors, setErrors] = useState({});
    const [statement, setStatement] = useState(null);
    const [failure, setFailure] = useState(null);
    const [busy, setBusy] = useState(null); // null | 'generate' | 'save' | 'csv'
    const [toast, setToast] = useState(null);
    const [saved, setSaved] = useState(null);
    const [usedAsOf, setUsedAsOf] = useState(false); // the statement on screen was asked for a chosen past instant
    const [employees, setEmployees] = useState([]);
    const { print, frame } = usePrintFrame();
    const dismissToast = useCallback(() => setToast(null), []);

    useEffect(() => {
        let cancelled = false;
        fetchAll('/api/v1/employees?active=true').then((result) => {
            if (!cancelled && result.ok) {
                setEmployees(result.rows);
            }
        });
        return () => {
            cancelled = true;
        };
    }, []);

    const employeeOptions = useMemo(
        () => employees.map((employee) => ({ id: employee.id, text: `${employee.employee_code} - ${employee.name}` })),
        [employees],
    );

    const range = form.mode === 'cutoff' ? cutoffRange(form.month, form.half) : null;

    function update(patch) {
        setForm((current) => ({ ...current, ...patch }));
    }

    function chooseEmployee(text) {
        const match = employeeOptions.find((option) => option.text === text);
        update({ employeeText: text, employeeId: match ? match.id : '' });
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    function check() {
        const found = validateRange(form);
        if (form.employeeText.trim() !== '' && form.employeeId === '') {
            found.employee = 'Pick an employee from the list, or clear the box.';
        }
        setErrors(found);
        return Object.keys(found).length === 0;
    }

    async function generate(event) {
        event?.preventDefault();
        if (!check()) {
            return;
        }
        setBusy('generate');
        setFailure(null);
        setSaved(null);
        const response = await request(`/api/v1/billing/statement?${toQuery(statementParams(form))}`);
        setBusy(null);
        if (response.status === 401) {
            signIn();
            return;
        }
        if (!response.ok) {
            setStatement(null);
            setFailure(response);
            return;
        }
        setStatement(response.body.data);
        setUsedAsOf(Boolean(form.asOf));
    }

    async function save() {
        if (busy) {
            return;
        }
        setBusy('save');
        setFailure(null);
        const body = statementParams(statementForm());
        if (usedAsOf) {
            body.as_of = statement.criteria.as_of;
        }
        const response = await request('/api/v1/billing-runs', { method: 'POST', body });
        setBusy(null);
        if (response.status === 401) {
            signIn();
            return;
        }
        if (!response.ok) {
            setFailure(response);
            return;
        }
        const run = response.body.data;
        setStatement(run.statement);
        setSaved(run);
        setToast(`Saved as billing run ${run.run_number}`);
    }

    /** The parameters of the statement on screen, not whatever the form says now. */
    function statementForm() {
        const c = statement.criteria;
        return {
            mode: c.cutoff_month ? 'cutoff' : 'custom',
            month: c.cutoff_month,
            half: String(c.cutoff_half ?? '1'),
            from: c.from,
            to: c.to,
            employeeType: c.employee_type ?? '',
            employeeId: c.employee_id ?? '',
            asOf: '',
        };
    }

    async function exportCsv() {
        setBusy('csv');
        const params = statementParams(statementForm());
        params.as_of = statement.criteria.as_of;
        const result = await downloadCsv(`/api/v1/billing/statement?${toQuery(params)}`, csvFileName(statement, saved));
        setBusy(null);
        if (result.ok) {
            setToast(`Exported ${result.filename}`);
        } else {
            setFailure({ status: result.status ?? 0, body: null, message: result.message });
        }
    }

    function printStatement() {
        print(printDocument(statement, saved), 'Billing statement to print');
    }

    const detail = failure?.status === 422 ? Object.values(failure.body?.error?.details ?? {}).flat()[0] : null;
    const failureText = failure ? (failure.message ?? detail ?? failureMessage(failure, 'The statement could not be made.')) : null;

    return (
        <AdminLayout title="Billing" requiredCapability="BILLING_MANAGE" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            {frame}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Billing</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        What the employees owe, from the credit ledger: TSC and SUB employees on one bill, by employee, meal type and dish. Pick any cut-off or any dates; run it again
                        at any date and it shows what changed. Nothing is saved until you press Save as billing run.
                    </p>
                </div>
                <Link to="/admin/billing/runs" className="flex min-h-11 items-center self-start rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                    Billing runs
                </Link>
            </div>

            <form onSubmit={generate} noValidate className="mb-6 space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4" aria-label="Billing statement options">
                <fieldset className="flex flex-wrap items-center gap-4">
                    <legend className="sr-only">Range</legend>
                    <label className="flex min-h-11 items-center gap-2 text-sm text-slate-200 lg:min-h-0">
                        <input type="radio" name="mode" checked={form.mode === 'cutoff'} onChange={() => update({ mode: 'cutoff' })} /> Cut-off
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm text-slate-200 lg:min-h-0">
                        <input type="radio" name="mode" checked={form.mode === 'custom'} onChange={() => update({ mode: 'custom' })} /> Any dates
                    </label>
                </fieldset>

                {form.mode === 'cutoff' ? (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label htmlFor="billing-month" className={LABEL}>
                                Month
                            </label>
                            <input id="billing-month" type="month" value={form.month} onChange={(event) => update({ month: event.target.value })} className={FIELD} aria-invalid={Boolean(errors.month)} />
                            {errors.month && <p className="mt-1 text-xs text-rose-400">{errors.month}</p>}
                        </div>
                        <div>
                            <label htmlFor="billing-half" className={LABEL}>
                                Cut-off
                            </label>
                            <select id="billing-half" value={form.half} onChange={(event) => update({ half: event.target.value })} className={FIELD}>
                                {HALVES.map((half) => (
                                    <option key={half.id} value={half.id}>
                                        {half.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <p className="self-end pb-2 font-mono text-xs text-slate-500">{range ? `${range.from} to ${range.to}` : ''}</p>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label htmlFor="billing-from" className={LABEL}>
                                From
                            </label>
                            <input id="billing-from" type="date" value={form.from} onChange={(event) => update({ from: event.target.value })} className={FIELD} aria-invalid={Boolean(errors.from)} />
                            {errors.from && <p className="mt-1 text-xs text-rose-400">{errors.from}</p>}
                        </div>
                        <div>
                            <label htmlFor="billing-to" className={LABEL}>
                                To (included)
                            </label>
                            <input id="billing-to" type="date" value={form.to} onChange={(event) => update({ to: event.target.value })} className={FIELD} aria-invalid={Boolean(errors.to)} />
                            {errors.to && <p className="mt-1 text-xs text-rose-400">{errors.to}</p>}
                        </div>
                        <p className="self-end pb-2 text-xs text-slate-500">Dates are Manila dates. Up to 366 days.</p>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label htmlFor="billing-type" className={LABEL}>
                            Employee type
                        </label>
                        <select id="billing-type" value={form.employeeType} onChange={(event) => update({ employeeType: event.target.value })} className={FIELD}>
                            <option value="">TSC and SUB</option>
                            {EMPLOYEE_TYPES.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="billing-employee" className={LABEL}>
                            One employee (optional)
                        </label>
                        <input
                            id="billing-employee"
                            list="billing-employee-options"
                            value={form.employeeText}
                            onChange={(event) => chooseEmployee(event.target.value)}
                            placeholder="Type a code or a name"
                            autoComplete="off"
                            className={FIELD}
                            aria-invalid={Boolean(errors.employee)}
                        />
                        <datalist id="billing-employee-options">
                            {employeeOptions.map((option) => (
                                <option key={option.id} value={option.text} />
                            ))}
                        </datalist>
                        {errors.employee && <p className="mt-1 text-xs text-rose-400">{errors.employee}</p>}
                    </div>
                    <div>
                        <label htmlFor="billing-asof" className={LABEL}>
                            As of (optional, Manila time)
                        </label>
                        <input id="billing-asof" type="datetime-local" value={form.asOf} onChange={(event) => update({ asOf: event.target.value })} className={FIELD} />
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <button type="submit" disabled={busy !== null} className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0">
                        {busy === 'generate' ? 'Generating…' : 'Generate'}
                    </button>
                </div>
            </form>

            {failureText && (
                <ErrorAlert message={failureText} onSignIn={failure.status === 401 ? signIn : undefined} onRetry={failure.status === 401 ? undefined : generate} />
            )}

            {statement && (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        {saved ? (
                            <p className="mr-auto text-sm text-emerald-300">
                                Saved as billing run{' '}
                                <Link to={`/admin/billing/runs/${saved.id}`} className="font-mono underline">
                                    {saved.run_number}
                                </Link>
                                . This is the copy to print.
                            </p>
                        ) : (
                            <p className="mr-auto text-sm text-slate-400">Not saved yet. Save it to keep an exact copy that can be reprinted later.</p>
                        )}
                        <button type="button" onClick={save} disabled={busy !== null || saved !== null} className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0">
                            {busy === 'save' ? 'Saving…' : 'Save as billing run'}
                        </button>
                        <button type="button" onClick={printStatement} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                            Print
                        </button>
                        <button type="button" onClick={exportCsv} disabled={busy !== null} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0">
                            {busy === 'csv' ? 'Exporting…' : 'Export CSV'}
                        </button>
                    </div>
                    <StatementView statement={statement} />
                </>
            )}
        </AdminLayout>
    );
}

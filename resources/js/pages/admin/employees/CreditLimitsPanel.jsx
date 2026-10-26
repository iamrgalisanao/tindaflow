import { useCallback, useEffect, useState } from 'react';
import { failureMessage, fieldErrors, request } from '../catalog/catalogApi';
import { formatLimit, normalizeLimit, typeLabel } from './employeeConfig';

function LimitRow({ policy, onSaved, onUnauthorized }) {
    const [text, setText] = useState(policy.limit_per_cutoff);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const inputId = `credit_limit_${policy.employee_type}`;
    const normalized = normalizeLimit(text);
    const changed = normalized !== null && normalized !== policy.limit_per_cutoff;

    async function save(event) {
        event.preventDefault();
        if (normalized === null) {
            setError('Enter an amount in pesos, such as 1500 or 1500.50.');
            return;
        }
        setSaving(true);
        setError(null);
        const result = await request(`/api/v1/credit-policies/${policy.employee_type}`, { method: 'PUT', body: { limit_per_cutoff: normalized } });
        setSaving(false);
        if (result.ok) {
            setText(result.body.limit_per_cutoff);
            onSaved(result.body);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        setError(fieldErrors(result).limit_per_cutoff ?? failureMessage(result, 'The limit could not be saved.'));
    }

    return (
        <form onSubmit={save} noValidate className="flex flex-wrap items-end gap-3">
            <div className="min-w-0">
                <label htmlFor={inputId} className="mb-1 block text-xs text-slate-400">
                    {typeLabel(policy.employee_type)} &mdash; limit per cut-off
                </label>
                <div className="flex items-center gap-2">
                    <span className="text-slate-500">₱</span>
                    <input
                        id={inputId}
                        type="text"
                        inputMode="decimal"
                        value={text}
                        aria-invalid={Boolean(error)}
                        onChange={(event) => {
                            setText(event.target.value);
                            setError(null);
                        }}
                        className={`min-h-11 w-36 rounded-md border bg-slate-950 px-3 py-1.5 text-right font-mono text-sm text-slate-100 focus:outline-none focus:ring-1 lg:min-h-0 ${
                            error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                        }`}
                    />
                </div>
            </div>
            <button type="submit" disabled={saving || !changed} className="min-h-11 rounded-md border border-emerald-700 px-3 py-1.5 text-sm text-emerald-300 hover:bg-emerald-950/40 disabled:opacity-40 lg:min-h-0">
                {saving ? 'Saving…' : 'Save'}
            </button>
            <p className="pb-2 font-mono text-[11px] text-slate-500">Now {formatLimit(policy.limit_per_cutoff)}</p>
            {error && (
                <p role="alert" className="w-full font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            )}
        </form>
    );
}

/**
 * The credit limit per cut-off of each employee type (creditPolicyList / creditPolicySetLimit). Changing a limit is recorded in the
 * audit log with the old and new value. Nothing is charged to credit yet; the limit is used once credit sales are switched on.
 */
export default function CreditLimitsPanel({ onUnauthorized, onSaved, readOnly = false }) {
    const [policies, setPolicies] = useState(null);
    const [failure, setFailure] = useState(null);

    const load = useCallback(async () => {
        const result = await request('/api/v1/credit-policies');
        if (result.ok) {
            setPolicies(result.body.data);
            setFailure(null);
        } else if (result.status === 401) {
            onUnauthorized();
        } else {
            setFailure(result);
        }
    }, [onUnauthorized]);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <section aria-label="Credit limits" className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-3">
            <h3 className="text-sm font-semibold text-slate-100">Credit limits</h3>
            <p className="mb-3 mt-1 max-w-2xl text-xs text-slate-400">
                What an employee may charge to credit in one cut-off. Each type has its own limit.{' '}
                {readOnly ? 'Only an administrator can change a limit.' : 'A change is recorded in the audit log.'}
            </p>
            {failure && (
                <p role="alert" className="text-sm text-rose-300">
                    {failureMessage(failure, 'The credit limits could not be loaded.')}{' '}
                    <button type="button" onClick={load} className="text-emerald-400 underline">
                        Try again
                    </button>
                </p>
            )}
            {!policies && !failure && <div className="h-12 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading credit limits" />}
            {policies && (
                <div className="flex flex-wrap gap-x-8 gap-y-3">
                    {policies.map((policy) =>
                        readOnly ? (
                            <p key={policy.employee_type} className="text-sm text-slate-200">
                                <span className="block text-xs text-slate-400">{typeLabel(policy.employee_type)} &mdash; limit per cut-off</span>
                                <span className="font-mono">{formatLimit(policy.limit_per_cutoff)}</span>
                            </p>
                        ) : (
                        <LimitRow
                            key={policy.employee_type}
                            policy={policy}
                            onUnauthorized={onUnauthorized}
                            onSaved={(saved) => {
                                setPolicies((prior) => prior.map((entry) => (entry.employee_type === saved.employee_type ? saved : entry)));
                                onSaved(`${typeLabel(saved.employee_type)} limit saved`);
                            }}
                        />
                        ),
                    )}
                </div>
            )}
        </section>
    );
}

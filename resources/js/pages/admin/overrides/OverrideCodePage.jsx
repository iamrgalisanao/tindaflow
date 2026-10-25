import { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Toast } from '../catalog/CatalogParts';
import { failureMessage, fieldErrors, request } from '../catalog/catalogApi';
import { CODE_HELP, statusText, validateCodeForm } from './overrideCodeForm';

const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

function Field({ id, label, hint, error, children }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs text-slate-400">
                {label}
            </label>
            {children}
            {error ? (
                <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            ) : (
                hint && <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
            )}
        </div>
    );
}

/**
 * /admin/override-code -- creditOverrideCodeGet / creditOverrideCodeSet (ADR-016 section 6, slice 3.3). A manager or administrator sets
 * their OWN code for approving a credit sale past the limit, after re-entering their account password. The code is shown nowhere, ever:
 * not here, not to the cashier, not to an administrator. To change it, set a new one; a forgotten code is cleared by an administrator
 * on the Users screen and set again here.
 */
export default function OverrideCodePage() {
    const [status, setStatus] = useState(null);
    const [failure, setFailure] = useState(null);
    const [values, setValues] = useState({ password: '', code: '', confirm: '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [toast, setToast] = useState(null);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(async () => {
        setFailure(null);
        const result = await request('/api/v1/credit-override-code');
        if (result.ok) {
            setStatus(result.body);
        } else {
            setFailure(result);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    function set(field, value) {
        setValues((prior) => ({ ...prior, [field]: value }));
        setErrors((prior) => ({ ...prior, [field]: undefined }));
    }

    async function submit(event) {
        event.preventDefault();
        const clientErrors = validateCodeForm(values);
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setSaving(true);
        setFormError(null);
        const result = await request('/api/v1/credit-override-code', { method: 'PUT', body: { password: values.password, code: values.code } });
        setSaving(false);
        // Whatever the answer, neither the password nor the code stays in the form.
        setValues({ password: '', code: '', confirm: '' });
        if (result.ok) {
            setStatus(result.body);
            setToast('Your override code is saved.');
            return;
        }
        if (result.status === 401) {
            setFormError('Your session expired. Sign in again, then save your code.');
            return;
        }
        const serverErrors = fieldErrors(result);
        if (Object.keys(serverErrors).length > 0) {
            setErrors(serverErrors);
            return;
        }
        setFormError(result.status === 429 ? 'Too many tries. Wait a few minutes, then try again.' : failureMessage(result, 'The code could not be saved.'));
    }

    return (
        <AdminLayout title="My override code" requiredCapability="CREDIT_LIMIT_OVERRIDE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <div className="max-w-xl space-y-5">
                <p className="text-sm text-slate-300">
                    When a cashier&apos;s credit sale would go past an employee&apos;s limit, you can approve that one sale by choosing your name at the till and typing this
                    code. It approves one sale only. Keep it to yourself: it is not your password, and nobody (not even an administrator) can read it back.
                </p>

                {failure && <ErrorAlert message={failureMessage(failure, 'Your code status could not be loaded.')} onRetry={load} />}
                {status && (
                    <p className="font-mono text-xs text-slate-400">
                        Status: <span data-testid="override-status" className="text-slate-100">{statusText(status)}</span>
                    </p>
                )}

                <form onSubmit={submit} noValidate className="space-y-4" aria-label="Set override code" autoComplete="off">
                    {formError && (
                        <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                            {formError}
                        </p>
                    )}
                    <Field id="override_password" label="Your account password" hint="Asked again so only you can set your code." error={errors.password}>
                        <input id="override_password" type="password" autoComplete="current-password" value={values.password} aria-invalid={Boolean(errors.password)} onChange={(event) => set('password', event.target.value)} className={inputClass(errors.password)} />
                    </Field>
                    <Field id="override_new_code" label={status?.has_code ? 'New override code' : 'Override code'} hint={CODE_HELP} error={errors.code}>
                        <input
                            id="override_new_code"
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={8}
                            value={values.code}
                            aria-invalid={Boolean(errors.code)}
                            onChange={(event) => set('code', event.target.value.replace(/\D/g, ''))}
                            className={`${inputClass(errors.code)} font-mono tracking-widest`}
                        />
                    </Field>
                    <Field id="override_confirm_code" label="Type the code again" error={errors.confirm}>
                        <input
                            id="override_confirm_code"
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={8}
                            value={values.confirm}
                            aria-invalid={Boolean(errors.confirm)}
                            onChange={(event) => set('confirm', event.target.value.replace(/\D/g, ''))}
                            className={`${inputClass(errors.confirm)} font-mono tracking-widest`}
                        />
                    </Field>
                    <button type="submit" disabled={saving} className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0">
                        {saving ? 'Saving…' : status?.has_code ? 'Change my code' : 'Save my code'}
                    </button>
                </form>
            </div>
        </AdminLayout>
    );
}

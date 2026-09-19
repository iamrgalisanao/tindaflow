import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AdminLayout from './AdminLayout';
import { ErrorAlert, Toast } from './catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, request } from './catalog/catalogApi';

const FIELDS = ['business_name', 'registered_name', 'tin', 'branch_code', 'business_address', 'invoice_header', 'invoice_footer', 'telephone', 'email'];
const REQUIRED = { business_name: 'Enter the business name.', registered_name: 'Enter the registered name.', tin: 'Enter the TIN.' };

const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

function Field({ id, label, required, hint, error, children }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs text-slate-400">
                {label} {required && <span className="text-emerald-400">*</span>}
            </label>
            {children}
            {error ? (
                <p id={`${id}_error`} role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            ) : (
                hint && <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
            )}
        </div>
    );
}

const toValues = (settings) => Object.fromEntries(FIELDS.map((field) => [field, settings[field] ?? '']));

/**
 * /admin/store-setup/business -- storeSettingsGet / storeSettingsUpdate (STORE_SETTINGS_MANAGE). The business
 * identity that is printed at the top of every invoice. A change applies to invoices issued from then on;
 * invoices already issued keep exactly what they were issued with. Only the fields that changed are sent.
 * The very first save (no settings row yet) sends everything, because the three identity fields are all
 * needed to create it. The tax registration is shown for reference and is managed on its own page.
 */
export default function StoreSettingsPage() {
    const { setUser } = useAuth();
    const [saved, setSaved] = useState(null);
    const [registration, setRegistration] = useState(null);
    const [values, setValues] = useState(null);
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState(null);
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [toast, setToast] = useState(null);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(() => {
        setFailure(null);
        request('/api/v1/store-settings').then((response) => {
            if (response.ok) {
                setSaved(toValues(response.body));
                setValues(toValues(response.body));
                setRegistration(response.body.current_tax_registration);
            } else {
                setFailure(response);
            }
        });
    }, []);

    useEffect(load, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    function set(field, value) {
        setValues((prior) => ({ ...prior, [field]: value }));
        setErrors((prior) => ({ ...prior, [field]: undefined }));
        setFormError(null);
    }

    const firstSave = saved !== null && saved.registered_name === '' && saved.tin === '';
    const changedFields = saved === null ? [] : FIELDS.filter((field) => values[field].trim() !== saved[field]);
    const dirty = firstSave || changedFields.length > 0;

    async function submit(event) {
        event.preventDefault();
        const clientErrors = {};
        Object.entries(REQUIRED).forEach(([field, message]) => {
            if (values[field].trim() === '') {
                clientErrors[field] = message;
            }
        });
        if (values.email.trim() !== '' && !/^\S+@\S+\.\S+$/.test(values.email.trim())) {
            clientErrors.email = 'Enter a valid email address.';
        }
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }

        setSaving(true);
        setFormError(null);
        const sending = firstSave ? FIELDS : changedFields;
        const body = Object.fromEntries(sending.map((field) => [field, values[field].trim() === '' ? null : values[field].trim()]));
        const result = await request('/api/v1/store-settings', { method: 'PATCH', body });
        setSaving(false);
        if (result.ok) {
            setSaved(toValues(result.body));
            setValues(toValues(result.body));
            setRegistration(result.body.current_tax_registration);
            setToast('Business details saved');
            return;
        }
        if (result.status === 401) {
            signIn();
            return;
        }
        const serverErrors = fieldErrors(result);
        if (Object.keys(serverErrors).length > 0) {
            setErrors(serverErrors);
            return;
        }
        setFormError(failureMessage(result, 'The business details could not be saved.'));
    }

    const previewLines = values
        ? [
              values.registered_name,
              values.business_address,
              [values.tin && `TIN: ${values.tin}`, values.branch_code && `Branch: ${values.branch_code}`].filter(Boolean).join(' · '),
              registration ? (registration.registration_type === 'VAT' ? 'VAT REGISTERED' : 'NON-VAT REGISTERED') : '',
              values.invoice_header,
          ].filter((line) => line && line.trim() !== '')
        : [];

    return (
        <AdminLayout title="Business details" requiredCapability="STORE_SETTINGS_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Business details</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Who you are, as printed at the top of every invoice. Changes apply to invoices issued from now on; invoices you have already issued keep what they were printed with.
                </p>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The business details could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {values === null && !failure && (
                <div className="space-y-3" aria-busy="true" aria-label="Loading business details">
                    <div className="h-20 animate-pulse rounded bg-slate-900" />
                    <div className="h-48 animate-pulse rounded bg-slate-900" />
                </div>
            )}

            {values && (
                <form onSubmit={submit} noValidate className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="space-y-5">
                        {firstSave && (
                            <p role="status" className="rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-200">
                                Your business details have not been saved yet, so invoices are printing without your name, address or TIN. Fill in the fields marked * and save.
                            </p>
                        )}
                        {formError && (
                            <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                                {formError}
                            </p>
                        )}

                        <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <h3 className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Business</h3>
                            <Field id="settings_business_name" label="Business name" required hint="The name customers know you by." error={errors.business_name}>
                                <input
                                    id="settings_business_name"
                                    type="text"
                                    autoComplete="off"
                                    maxLength={255}
                                    value={values.business_name}
                                    aria-invalid={Boolean(errors.business_name)}
                                    onChange={(event) => set('business_name', event.target.value)}
                                    className={inputClass(errors.business_name)}
                                />
                            </Field>
                            <Field id="settings_registered_name" label="Registered name" required hint="The name your business is registered under. This is what an invoice shows." error={errors.registered_name}>
                                <input
                                    id="settings_registered_name"
                                    type="text"
                                    autoComplete="off"
                                    maxLength={255}
                                    value={values.registered_name}
                                    aria-invalid={Boolean(errors.registered_name)}
                                    onChange={(event) => set('registered_name', event.target.value)}
                                    className={inputClass(errors.registered_name)}
                                />
                            </Field>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field id="settings_tin" label="TIN" required error={errors.tin}>
                                    <input
                                        id="settings_tin"
                                        type="text"
                                        autoComplete="off"
                                        maxLength={50}
                                        value={values.tin}
                                        aria-invalid={Boolean(errors.tin)}
                                        onChange={(event) => set('tin', event.target.value)}
                                        className={`${inputClass(errors.tin)} font-mono`}
                                    />
                                </Field>
                                <Field id="settings_branch_code" label="Branch code" hint="Optional." error={errors.branch_code}>
                                    <input
                                        id="settings_branch_code"
                                        type="text"
                                        autoComplete="off"
                                        maxLength={50}
                                        value={values.branch_code}
                                        aria-invalid={Boolean(errors.branch_code)}
                                        onChange={(event) => set('branch_code', event.target.value)}
                                        className={`${inputClass(errors.branch_code)} font-mono`}
                                    />
                                </Field>
                            </div>
                            <Field id="settings_business_address" label="Business address" error={errors.business_address}>
                                <input
                                    id="settings_business_address"
                                    type="text"
                                    autoComplete="off"
                                    maxLength={255}
                                    value={values.business_address}
                                    aria-invalid={Boolean(errors.business_address)}
                                    onChange={(event) => set('business_address', event.target.value)}
                                    className={inputClass(errors.business_address)}
                                />
                            </Field>
                        </section>

                        <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <h3 className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Invoice text</h3>
                            <Field id="settings_invoice_header" label="Header" hint="Optional. Printed under your business details, for example a slogan or opening hours." error={errors.invoice_header}>
                                <textarea
                                    id="settings_invoice_header"
                                    rows={3}
                                    maxLength={500}
                                    value={values.invoice_header}
                                    aria-invalid={Boolean(errors.invoice_header)}
                                    onChange={(event) => set('invoice_header', event.target.value)}
                                    className={inputClass(errors.invoice_header)}
                                />
                            </Field>
                            <Field id="settings_invoice_footer" label="Footer" hint="Optional. Printed at the bottom, for example a thank-you or a return policy." error={errors.invoice_footer}>
                                <textarea
                                    id="settings_invoice_footer"
                                    rows={3}
                                    maxLength={500}
                                    value={values.invoice_footer}
                                    aria-invalid={Boolean(errors.invoice_footer)}
                                    onChange={(event) => set('invoice_footer', event.target.value)}
                                    className={inputClass(errors.invoice_footer)}
                                />
                            </Field>
                        </section>

                        <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <h3 className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Contact</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field id="settings_telephone" label="Telephone" hint="Optional." error={errors.telephone}>
                                    <input
                                        id="settings_telephone"
                                        type="text"
                                        autoComplete="off"
                                        maxLength={50}
                                        value={values.telephone}
                                        aria-invalid={Boolean(errors.telephone)}
                                        onChange={(event) => set('telephone', event.target.value)}
                                        className={inputClass(errors.telephone)}
                                    />
                                </Field>
                                <Field id="settings_email" label="Email" hint="Optional." error={errors.email}>
                                    <input
                                        id="settings_email"
                                        type="email"
                                        autoComplete="off"
                                        maxLength={255}
                                        value={values.email}
                                        aria-invalid={Boolean(errors.email)}
                                        onChange={(event) => set('email', event.target.value)}
                                        className={inputClass(errors.email)}
                                    />
                                </Field>
                            </div>
                        </section>

                        <div className="flex items-center gap-3">
                            <button
                                type="submit"
                                disabled={saving || !dirty}
                                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                            >
                                {saving ? 'Saving…' : 'Save changes'}
                            </button>
                            {!dirty && !firstSave && <span className="text-xs text-slate-500">Nothing to save.</span>}
                        </div>
                    </div>

                    <aside className="space-y-4">
                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <h3 className="mb-2 font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Tax registration</h3>
                            {registration ? (
                                <p className="text-sm text-slate-200">
                                    {registration.registration_type === 'VAT' ? 'VAT registered' : 'Non-VAT registered'}
                                    <span className="text-slate-500"> since {registration.effective_from}</span>
                                </p>
                            ) : (
                                <p className="text-sm text-amber-400">No tax registration is on record, so sales cannot be completed yet.</p>
                            )}
                            <Link to="/admin/store-setup/tax-registrations" className="mt-2 inline-block text-xs text-emerald-400 hover:underline">
                                Manage tax registrations
                            </Link>
                        </div>

                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <h3 className="mb-2 font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Top of your invoices</h3>
                            {previewLines.length === 0 ? (
                                <p className="text-xs text-slate-500">Fill in the details to see how they will print.</p>
                            ) : (
                                <div className="rounded bg-white px-3 py-3 text-center font-mono text-[12px] leading-snug text-black">
                                    {previewLines.map((line, index) => (
                                        <p key={index} className={`whitespace-pre-line break-words ${index === 0 ? 'font-bold' : ''}`}>
                                            {line}
                                        </p>
                                    ))}
                                </div>
                            )}
                            <p className="mt-2 text-[11px] text-slate-500">Shown for new invoices only.</p>
                        </div>
                    </aside>
                </form>
            )}
        </AdminLayout>
    );
}

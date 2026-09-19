import { useState } from 'react';
import SlideOver from '../SlideOver';
import { failureMessage, fieldErrors, request } from '../catalog/catalogApi';

export const ROLES = [
    { id: 'ADMIN', label: 'ADMIN', hint: 'Everything, including managing users.' },
    { id: 'MANAGER', label: 'MANAGER', hint: 'Runs the store day to day: reports, catalog and approvals.' },
    { id: 'CASHIER', label: 'CASHIER', hint: 'Rings up sales at a terminal.' },
];

const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

/** 16 characters from an unambiguous alphabet, drawn with the browser's CSPRNG. */
function generatePassword() {
    const bytes = new Uint32Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => PASSWORD_ALPHABET[byte % PASSWORD_ALPHABET.length]).join('');
}

function validate(values, editing) {
    const errors = {};
    if (values.name.trim() === '') {
        errors.name = 'Enter a name.';
    }
    if (!/^\S+@\S+\.\S+$/.test(values.email.trim())) {
        errors.email = 'Enter a valid email address.';
    }
    if (!editing && values.password === '') {
        errors.password = 'Enter a password of at least 8 characters.';
    } else if (values.password !== '' && values.password.length < 8) {
        errors.password = 'Use at least 8 characters.';
    }
    return errors;
}

const inputClass = (error, mono = false) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${mono ? 'font-mono' : ''} ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

function Field({ id, label, required, hint, error, children, action }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between">
                <label htmlFor={id} className="text-xs text-slate-400">
                    {label} {required && <span className="text-emerald-400">*</span>}
                </label>
                {action}
            </div>
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

/**
 * Slide-over for userCreate / userUpdate. The client checks shape only; a duplicate email (or the
 * last-administrator role rule) comes back from the server as a field error. The role lock and the
 * missing Deactivate button for your own account and for the only active administrator are a UI
 * guard -- the API itself only refuses demoting the last active administrator.
 */
export default function UserFormPanel({
    user,
    isSelf,
    isLastAdmin,
    onSaved,
    onRequestDeactivate,
    onActivate,
    onClose,
    onUnauthorized,
}) {
    const editing = Boolean(user);
    const [values, setValues] = useState({ name: user?.name ?? '', email: user?.email ?? '', role: user?.role ?? 'CASHIER', password: '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [copied, setCopied] = useState(false);

    const roleLocked = editing && (isSelf || isLastAdmin);

    function set(field, value) {
        setValues((prior) => ({ ...prior, [field]: value }));
        setErrors((prior) => ({ ...prior, [field]: undefined }));
    }

    async function copyPassword() {
        try {
            await navigator.clipboard.writeText(values.password);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setFormError('Copying is not available here; select the password and copy it by hand.');
        }
    }

    async function submit(event) {
        event.preventDefault();
        const clientErrors = validate(values, editing);
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setSaving(true);
        setFormError(null);
        const body = { name: values.name.trim(), email: values.email.trim(), role: values.role };
        if (values.password !== '') {
            body.password = values.password;
        }
        const result = await request(editing ? `/api/v1/users/${user.id}` : '/api/v1/users', {
            method: editing ? 'PATCH' : 'POST',
            body,
        });
        setSaving(false);
        if (result.ok) {
            onSaved(result.body, editing);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        const serverErrors = fieldErrors(result);
        if (Object.keys(serverErrors).length > 0) {
            setErrors(serverErrors);
            return;
        }
        setFormError(failureMessage(result, 'The user could not be saved.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="user_form"
                disabled={saving}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Saving…' : editing ? 'Save changes' : 'Create user'}
            </button>
        </div>
    );

    return (
        <SlideOver titleId="user_panel_title" title={editing ? 'Edit user' : 'New user'} badge={editing ? user.role : null} onClose={onClose} footer={footer}>
            <form id="user_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}

                <Field id="user_name" label="Name" required error={errors.name}>
                    <input
                        id="user_name"
                        type="text"
                        autoComplete="off"
                        value={values.name}
                        aria-invalid={Boolean(errors.name)}
                        onChange={(event) => set('name', event.target.value)}
                        className={inputClass(errors.name)}
                    />
                </Field>

                <Field id="user_email" label="Email" required hint="They sign in with this. It must be unique in your store." error={errors.email}>
                    <input
                        id="user_email"
                        type="email"
                        autoComplete="off"
                        value={values.email}
                        aria-invalid={Boolean(errors.email)}
                        onChange={(event) => set('email', event.target.value)}
                        className={inputClass(errors.email)}
                    />
                </Field>

                <fieldset disabled={roleLocked}>
                    <legend className="mb-1 text-xs text-slate-400">
                        Role <span className="text-emerald-400">*</span>
                    </legend>
                    {errors.role && (
                        <p role="alert" className="mb-1 font-mono text-[11px] text-rose-400">
                            &#9888; {errors.role}
                        </p>
                    )}
                    <div className="grid grid-cols-1 gap-2">
                        {ROLES.map((role) => (
                            <label
                                key={role.id}
                                className={`flex items-start gap-2 rounded-md border p-2.5 ${roleLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'} ${
                                    values.role === role.id ? 'border-emerald-500 bg-emerald-950/20' : 'border-slate-700 bg-slate-950/40 hover:bg-slate-800/60'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="role"
                                    value={role.id}
                                    checked={values.role === role.id}
                                    onChange={() => set('role', role.id)}
                                    className="mt-0.5 accent-emerald-500"
                                />
                                <span>
                                    <span className="block font-mono text-xs font-semibold text-slate-100">{role.label}</span>
                                    <span className="block text-[11px] text-slate-400">{role.hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {roleLocked && (
                        <p className="mt-1 text-[11px] text-slate-500">
                            {isSelf ? "You can't change your own role." : "This is the only active administrator, so the role can't be changed."}
                        </p>
                    )}
                </fieldset>

                <Field
                    id="user_password"
                    label={editing ? 'New password' : 'Password'}
                    required={!editing}
                    hint={editing ? 'Leave blank to keep the current password.' : 'At least 8 characters. Share it with them securely; they can be given a new one any time.'}
                    error={errors.password}
                    action={
                        <span className="flex items-center gap-3 text-[11px]">
                            <button type="button" onClick={() => setShowPassword((shown) => !shown)} className="text-slate-400 hover:text-slate-200">
                                {showPassword ? 'Hide' : 'Show'}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    set('password', generatePassword());
                                    setShowPassword(true);
                                }}
                                className="text-emerald-400 hover:underline"
                            >
                                Generate
                            </button>
                            {values.password !== '' && (
                                <button type="button" onClick={copyPassword} className="text-emerald-400 hover:underline">
                                    {copied ? 'Copied' : 'Copy'}
                                </button>
                            )}
                        </span>
                    }
                >
                    <input
                        id="user_password"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={values.password}
                        aria-invalid={Boolean(errors.password)}
                        onChange={(event) => set('password', event.target.value)}
                        className={inputClass(errors.password, true)}
                    />
                </Field>

                {editing && (
                    <div className="rounded-md border border-slate-800 bg-slate-950/60 px-3 py-2">
                        <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">Current access ({user.capabilities.length})</p>
                        <div className="flex flex-wrap gap-1">
                            {user.capabilities.map((capability) => (
                                <span key={capability} className="rounded border border-slate-700 px-1.5 py-0.5 font-mono text-[10px] text-slate-300">
                                    {capability}
                                </span>
                            ))}
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500">Access follows the role and updates after you save a role change.</p>
                    </div>
                )}

                {editing && (
                    <div className="border-t border-slate-800 pt-4">
                        <p className="font-mono text-[11px] font-semibold uppercase tracking-wider text-rose-400">
                            {user.active ? 'Deactivate this user' : 'Reactivate this user'}
                        </p>
                        <p className="mb-3 mt-1 text-xs text-slate-400">
                            {user.active
                                ? 'They are signed out on their next request and cannot sign in until you reactivate them. Their past sales and records stay as they are.'
                                : 'This user cannot sign in. Reactivating lets them sign in again with their existing password.'}
                        </p>
                        {user.active ? (
                            isSelf || isLastAdmin ? (
                                <p className="text-xs text-slate-500">
                                    {isSelf ? "You can't deactivate your own account." : "This is the only active administrator, so it can't be deactivated."}
                                </p>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => onRequestDeactivate(user)}
                                    className="min-h-11 rounded border border-rose-700 px-3 py-1.5 text-xs font-medium text-rose-400 hover:bg-rose-950/40 lg:min-h-0"
                                >
                                    Deactivate user
                                </button>
                            )
                        ) : (
                            <button
                                type="button"
                                onClick={() => onActivate(user)}
                                className="min-h-11 rounded border border-emerald-700 px-3 py-1.5 text-xs font-medium text-emerald-400 hover:bg-emerald-950/40 lg:min-h-0"
                            >
                                Reactivate user
                            </button>
                        )}
                    </div>
                )}
            </form>
        </SlideOver>
    );
}

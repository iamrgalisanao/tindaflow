import { useState } from 'react';

export const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

export const primaryButton = 'min-h-11 rounded-md bg-emerald-500 px-3 py-1.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0';
export const secondaryButton = 'min-h-11 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0';
export const linkButton = 'min-h-11 text-emerald-400 hover:underline disabled:opacity-40 lg:min-h-0';

export function FieldError({ message }) {
    return message ? (
        <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
            &#9888; {message}
        </p>
    ) : null;
}

/** A one-input form: add or rename something by name. */
export function NameForm({ initial = '', label, submitLabel, busy, error, onSubmit, onCancel }) {
    const [name, setName] = useState(initial);
    const id = `menu_name_${label.replaceAll(/\W+/g, '_')}`;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(name.trim());
            }}
            className="flex flex-col gap-2 sm:flex-row sm:items-start"
            noValidate
        >
            <div className="min-w-0 flex-1">
                <label htmlFor={id} className="sr-only">
                    {label}
                </label>
                <input id={id} type="text" autoFocus value={name} placeholder={label} aria-invalid={Boolean(error)} onChange={(event) => setName(event.target.value)} className={inputClass(error)} />
                <FieldError message={error} />
            </div>
            <div className="flex gap-2">
                <button type="submit" disabled={busy || name.trim() === ''} className={primaryButton}>
                    {busy ? 'Saving…' : submitLabel}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className={secondaryButton}>
                        Cancel
                    </button>
                )}
            </div>
        </form>
    );
}

/** Up and down arrows that move a row among its siblings. */
export function MoveButtons({ name, index, count, busy, onMove }) {
    return (
        <>
            <button type="button" aria-label={`Move ${name} up`} disabled={busy || index === 0} onClick={() => onMove(-1)} className="min-h-11 px-1 text-slate-300 hover:text-emerald-400 disabled:opacity-30 lg:min-h-0">
                &uarr;
            </button>
            <button type="button" aria-label={`Move ${name} down`} disabled={busy || index === count - 1} onClick={() => onMove(1)} className="min-h-11 px-1 text-slate-300 hover:text-emerald-400 disabled:opacity-30 lg:min-h-0">
                &darr;
            </button>
        </>
    );
}

/** Loading skeleton shared by the menu screens. */
export function Loading({ label }) {
    return (
        <div className="space-y-2" aria-busy="true" aria-label={label}>
            {[0, 1, 2, 3].map((n) => (
                <div key={n} className="h-9 animate-pulse rounded bg-slate-900" />
            ))}
        </div>
    );
}

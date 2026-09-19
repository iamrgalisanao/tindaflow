import { useEffect, useRef } from 'react';

const TAX_BADGE = {
    VATABLE: 'border-emerald-700/70 text-emerald-400',
    VAT_EXEMPT: 'border-slate-600 text-slate-300',
    ZERO_RATED: 'border-sky-700/70 text-sky-300',
    NON_VAT: 'border-amber-700/70 text-amber-400',
};

export function TaxBadge({ taxClass }) {
    return (
        <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${TAX_BADGE[taxClass] ?? TAX_BADGE.VAT_EXEMPT}`}>
            {taxClass.replaceAll('_', ' ')}
        </span>
    );
}

export function ActiveBadge({ active }) {
    return (
        <span
            className={`inline-block rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${
                active ? 'border-emerald-700/70 bg-emerald-950/70 text-emerald-300' : 'border-slate-700 bg-slate-800/60 text-slate-500'
            }`}
        >
            {active ? 'ACTIVE' : 'INACTIVE'}
        </span>
    );
}

export function Toast({ message, onDismiss }) {
    useEffect(() => {
        const timer = setTimeout(onDismiss, 5000);
        return () => clearTimeout(timer);
    }, [message, onDismiss]);

    return (
        <div
            role="status"
            className="fixed right-4 top-4 z-[60] flex max-w-[calc(100vw-2rem)] items-center gap-3 rounded-md border border-emerald-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
        >
            <span aria-hidden="true" className="text-emerald-400">&#10003;</span>
            <span className="min-w-0 break-words">{message}</span>
            <button type="button" onClick={onDismiss} aria-label="Dismiss" className="text-slate-400 hover:text-slate-200">
                &times;
            </button>
        </div>
    );
}

/** Level-3 modal for a consequential action. Escape and the backdrop cancel; focus starts on Cancel. */
export function ConfirmDialog({ title, body, confirmLabel, busy, onConfirm, onCancel }) {
    const cancelRef = useRef(null);

    useEffect(() => {
        cancelRef.current?.focus();
        // Capture phase + stopPropagation: Escape must close only this dialog, not the slide-over behind it.
        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onCancel();
            }
        }
        document.addEventListener('keydown', onKeyDown, true);
        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, [onCancel]);

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-label={title}>
            <div className="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" onClick={onCancel} aria-hidden="true" />
            <div className="relative w-full max-w-md rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-[0_4px_12px_rgba(0,0,0,0.45)]">
                <h3 className="text-base font-semibold text-slate-100">{title}</h3>
                <p className="mt-2 text-sm text-slate-400">{body}</p>
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        ref={cancelRef}
                        type="button"
                        onClick={onCancel}
                        className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onConfirm}
                        className="min-h-11 rounded-md border border-rose-600 bg-rose-950/60 px-3 py-2 text-sm font-medium text-rose-300 hover:bg-rose-600 hover:text-white disabled:opacity-50 lg:min-h-0"
                    >
                        {busy ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

export function Pager({ meta, onPage }) {
    if (!meta) {
        return null;
    }
    const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.per_page + 1;
    const to = Math.min(meta.page * meta.per_page, meta.total);
    return (
        <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
            <span className="font-mono">
                Showing {from}&ndash;{to} of {meta.total}
            </span>
            {meta.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        disabled={meta.page === 1}
                        onClick={() => onPage(meta.page - 1)}
                        className="min-h-11 rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40 lg:min-h-0"
                    >
                        Previous
                    </button>
                    <span className="px-1 font-mono">
                        {meta.page} / {meta.last_page}
                    </span>
                    <button
                        type="button"
                        disabled={meta.page === meta.last_page}
                        onClick={() => onPage(meta.page + 1)}
                        className="min-h-11 rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40 lg:min-h-0"
                    >
                        Next
                    </button>
                </div>
            )}
        </div>
    );
}

export function ErrorAlert({ message, onRetry, onSignIn }) {
    return (
        <div role="alert" className="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
            <span className="min-w-0 flex-1">{message}</span>
            {onSignIn && (
                <button type="button" onClick={onSignIn} className="min-h-11 rounded border border-emerald-600 px-3 py-1 text-xs font-medium text-emerald-300 hover:bg-emerald-950 lg:min-h-0">
                    Sign in
                </button>
            )}
            {onRetry && (
                <button type="button" onClick={onRetry} className="min-h-11 rounded border border-rose-700 px-3 py-1 text-xs font-medium text-rose-300 hover:bg-rose-900/40 lg:min-h-0">
                    Retry
                </button>
            )}
        </div>
    );
}

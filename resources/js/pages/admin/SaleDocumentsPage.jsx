import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import AdminLayout from './AdminLayout';
import { ErrorAlert, Toast } from './catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, request } from './catalog/catalogApi';
import { useCanConfigureFiscal } from './FiscalLockNotice';

const MODES = [
    {
        id: 'INVOICE',
        title: 'Invoice',
        summary: 'Every sale gets a numbered invoice from your invoice series, and the electronic journal records it. This needs a fiscal installation, an invoice series and a tax registration.',
    },
    {
        id: 'SLIP',
        title: 'Slip',
        summary:
            'Every sale prints a small slip with its own running number for each terminal. The slip is not an invoice: no invoice number is used, no invoice or journal entry is written, and no tax breakdown is printed. Use this only if you issue your invoices yourself, outside TindaFlow.',
    },
];

const EFFECTS = {
    SLIP: [
        'From the next sale on, sales print a slip instead of an invoice. Sales already made keep the invoice they were issued.',
        'The fiscal installation, invoice series and tax registration are no longer needed to start selling.',
        'Shifts, cash counts and the shift report work exactly as before. The end-of-day close is printed as an end-of-day report instead of a Z-reading.',
    ],
    INVOICE: [
        'From the next sale on, sales get a numbered invoice again. Slips already printed stay as they are and can still be reprinted.',
        'A fiscal installation, an invoice series and a tax registration are needed before the till can sell.',
    ],
};

const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

/**
 * /admin/store-setup/sale-documents -- saleDocumentSettingsGet, saleDocumentModeSet, slipFooterSet (ADR-016). How the store documents a
 * sale (the invoice, or a small slip for a store that issues its own invoices) and the line printed at the bottom of every slip.
 * Switching the mode is the ADR-014 vendor unlock (FISCAL_CONFIGURATION_MANAGE): without it the page only shows the current mode. The
 * server refuses the switch while a shift or business day is open; this page says so up front and shows what the switch does.
 */
export default function SaleDocumentsPage() {
    const { setUser, user } = useAuth();
    const canConfigure = useCanConfigureFiscal();
    const canEditFooter = user.capabilities.includes('STORE_SETTINGS_MANAGE');
    const [settings, setSettings] = useState(null);
    const [failure, setFailure] = useState(null);
    const [target, setTarget] = useState(null);
    const [modeError, setModeError] = useState(null);
    const [switching, setSwitching] = useState(false);
    const [footer, setFooter] = useState('');
    const [footerError, setFooterError] = useState(null);
    const [savingFooter, setSavingFooter] = useState(false);
    const [toast, setToast] = useState(null);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(() => {
        setFailure(null);
        request('/api/v1/sale-document-settings').then((response) => {
            if (response.ok) {
                setSettings(response.body);
                setFooter(response.body.slip_footer ?? '');
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

    const somethingOpen = settings !== null && (settings.open_shifts > 0 || settings.open_fiscal_days > 0);

    async function switchMode() {
        setSwitching(true);
        setModeError(null);
        const result = await request('/api/v1/sale-document-settings/mode', { method: 'PUT', body: { sale_document_mode: target } });
        setSwitching(false);
        if (result.ok) {
            setSettings(result.body);
            setTarget(null);
            setToast(result.body.sale_document_mode === 'SLIP' ? 'Sales now print a slip' : 'Sales now get an invoice');
            return;
        }
        if (result.status === 401) {
            signIn();
            return;
        }
        if (result.body?.error?.code === 'SALE_DOCUMENT_MODE_CHANGE_BLOCKED') {
            load();
        }
        setModeError(failureMessage(result, 'The change could not be saved.'));
    }

    async function saveFooter(event) {
        event.preventDefault();
        setSavingFooter(true);
        setFooterError(null);
        const result = await request('/api/v1/sale-document-settings/slip-footer', { method: 'PUT', body: { slip_footer: footer.trim() === '' ? null : footer.trim() } });
        setSavingFooter(false);
        if (result.ok) {
            setSettings(result.body);
            setFooter(result.body.slip_footer ?? '');
            setToast('Slip footer saved');
            return;
        }
        if (result.status === 401) {
            signIn();
            return;
        }
        setFooterError(fieldErrors(result).slip_footer ?? failureMessage(result, 'The footer could not be saved.'));
    }

    const footerDirty = settings !== null && footer.trim() !== (settings.slip_footer ?? '');
    const current = settings?.sale_document_mode;

    return (
        <AdminLayout title="Sale documents" requiredCapability="STORE_SETTINGS_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Sale documents</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">What the till gives for every sale, and the line printed at the bottom of a slip.</p>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The sale document settings could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {settings === null && !failure && <div className="h-48 animate-pulse rounded bg-slate-900" aria-busy="true" aria-label="Loading sale document settings" />}

            {settings && (
                <div className="grid max-w-3xl grid-cols-1 gap-5">
                    <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4" aria-labelledby="document_mode_heading">
                        <h3 id="document_mode_heading" className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            How sales are documented
                        </h3>

                        <ul className="space-y-3">
                            {MODES.map((mode) => (
                                <li key={mode.id} className={`rounded-md border p-3 ${current === mode.id ? 'border-emerald-700 bg-emerald-950/20' : 'border-slate-800'}`}>
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-sm font-semibold text-slate-100">{mode.title}</p>
                                        {current === mode.id ? (
                                            <span className="rounded border border-emerald-700/70 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-emerald-400">IN USE</span>
                                        ) : (
                                            canConfigure && (
                                                <button
                                                    type="button"
                                                    disabled={somethingOpen}
                                                    onClick={() => {
                                                        setModeError(null);
                                                        setTarget(mode.id);
                                                    }}
                                                    className="min-h-11 rounded-md border border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-200 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                                                >
                                                    Switch to {mode.title.toLowerCase()}
                                                </button>
                                            )
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm text-slate-400">{mode.summary}</p>
                                </li>
                            ))}
                        </ul>

                        {!canConfigure && (
                            <p role="note" className="rounded-md border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-sm text-amber-300">
                                Changing this is locked. It is set during installation or a support visit; to change it, ask your TindaFlow provider to unlock it for you.
                            </p>
                        )}

                        {canConfigure && somethingOpen && (
                            <p role="status" className="rounded-md border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-sm text-amber-300">
                                It cannot be changed while a shift or business day is open. Right now {settings.open_shifts} shift{settings.open_shifts === 1 ? ' is' : 's are'} open and{' '}
                                {settings.open_fiscal_days} business day{settings.open_fiscal_days === 1 ? ' is' : 's are'} open. Close every shift and the business day on every terminal first.
                            </p>
                        )}

                        {target && (
                            <div role="group" aria-label="Confirm the change" className="space-y-3 rounded-md border border-slate-700 bg-slate-950 p-3">
                                <p className="text-sm font-semibold text-slate-100">Switch to {target === 'SLIP' ? 'slip' : 'invoice'}?</p>
                                <ul className="list-disc space-y-1 pl-5 text-sm text-slate-300">
                                    {EFFECTS[target].map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                                {modeError && (
                                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                                        {modeError}
                                    </p>
                                )}
                                <div className="flex gap-3">
                                    <button
                                        type="button"
                                        disabled={switching}
                                        onClick={switchMode}
                                        className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-40 lg:min-h-0"
                                    >
                                        {switching ? 'Switching…' : 'Yes, switch'}
                                    </button>
                                    <button type="button" onClick={() => setTarget(null)} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        )}
                    </section>

                    <form onSubmit={saveFooter} noValidate className="space-y-3 rounded-lg border border-slate-800 bg-slate-900 p-4">
                        <h3 className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">Slip footer</h3>
                        <div>
                            <label htmlFor="slip_footer" className="mb-1 block text-xs text-slate-400">
                                Line printed at the bottom of every slip
                            </label>
                            <textarea
                                id="slip_footer"
                                rows={2}
                                maxLength={200}
                                value={footer}
                                disabled={!canEditFooter}
                                aria-invalid={Boolean(footerError)}
                                onChange={(event) => {
                                    setFooter(event.target.value);
                                    setFooterError(null);
                                }}
                                className={inputClass(footerError)}
                            />
                            {footerError ? (
                                <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                                    &#9888; {footerError}
                                </p>
                            ) : (
                                <p className="mt-1 text-[11px] text-slate-500">Optional and empty by default. You decide the wording. It applies to slips printed from now on.</p>
                            )}
                        </div>
                        <button
                            type="submit"
                            disabled={savingFooter || !footerDirty || !canEditFooter}
                            className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                        >
                            {savingFooter ? 'Saving…' : 'Save footer'}
                        </button>
                    </form>
                </div>
            )}
        </AdminLayout>
    );
}

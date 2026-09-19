import { useRef, useState } from 'react';
import SlideOver from '../SlideOver';
import { fieldErrors, request } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';
import { reversalMessage } from './salesParts';

/**
 * Slide-over for saleVoid. A void cancels the whole sale and puts every item back in stock, and only works
 * while the sale's own fiscal day is still open. Someone who may approve voids executes it immediately;
 * anyone else sends a request that a manager approves later -- the panel says which will happen. One
 * Idempotency-Key per intent: reused when the same request is retried after a dropped connection,
 * replaced as soon as the reason changes.
 */
export default function VoidSalePanel({ sale, executesImmediately, onDone, onClose, onUnauthorized }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const keyRef = useRef(crypto.randomUUID());

    async function submit(event) {
        event.preventDefault();
        if (reason.trim() === '') {
            setError('Say why this sale is being voided.');
            return;
        }
        setSaving(true);
        setFormError(null);
        const result = await request(`/api/v1/sales/${sale.id}/void`, {
            method: 'POST',
            body: { reason: reason.trim() },
            headers: { 'Idempotency-Key': keyRef.current },
        });
        setSaving(false);
        if (result.ok) {
            onDone(result.body);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        const serverErrors = fieldErrors(result);
        if (serverErrors.reason) {
            setError(serverErrors.reason);
            return;
        }
        if (result.status === 0) {
            setFormError('The connection dropped, so this may not have been recorded. Press the button again — it will not be applied twice.');
            return;
        }
        keyRef.current = crypto.randomUUID();
        setFormError(reversalMessage(result, 'The void could not be recorded.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="void_form"
                disabled={saving}
                className="min-h-11 rounded-md border border-rose-600 bg-rose-950/60 px-5 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-600 hover:text-white disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Working…' : executesImmediately ? 'Void sale' : 'Request void'}
            </button>
        </div>
    );

    return (
        <SlideOver titleId="void_panel_title" title="Void sale" badge={sale.invoice_number ? `#${sale.invoice_number}` : null} onClose={onClose} footer={footer}>
            <form id="void_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}

                <div className="rounded-md border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm text-slate-300">
                    <p>
                        This cancels the <span className="font-mono text-slate-100">{formatMoney(sale.grand_total)}</span> sale in full and puts every item back in stock.
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                        A void only works while the sale&apos;s fiscal day is still open. After that, refund the items instead.
                    </p>
                </div>

                <p className="text-xs text-slate-400">
                    {executesImmediately
                        ? 'You can approve voids, so this takes effect as soon as you confirm. It is recorded against your open shift on this terminal.'
                        : 'A manager has to approve this before anything changes. You will find it under Approvals once it is decided.'}
                </p>

                <div>
                    <label htmlFor="void_reason" className="mb-1 block text-xs text-slate-400">
                        Reason <span className="text-emerald-400">*</span>
                    </label>
                    <textarea
                        id="void_reason"
                        rows={3}
                        maxLength={255}
                        value={reason}
                        aria-invalid={Boolean(error)}
                        onChange={(event) => {
                            keyRef.current = crypto.randomUUID();
                            setReason(event.target.value);
                            setError(null);
                        }}
                        className={`w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:outline-none focus:ring-1 ${
                            error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                        }`}
                    />
                    {error ? (
                        <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                            &#9888; {error}
                        </p>
                    ) : (
                        <p className="mt-1 text-[11px] text-slate-500">Kept with the record and shown to whoever approves it.</p>
                    )}
                </div>
            </form>
        </SlideOver>
    );
}

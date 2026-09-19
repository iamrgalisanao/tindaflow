import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import SlideOver from '../SlideOver';
import { failureMessage, fieldErrors, request } from '../catalog/catalogApi';
import { formatDateTime, formatMoney, shortId } from '../reports/formatters';
import { trimQuantity } from './refundMath';
import { DISPOSITIONS, StatusBadge, reversalMessage } from './salesParts';

const DISPOSITION_LABEL = Object.fromEntries(DISPOSITIONS.map((disposition) => [disposition.id, disposition.label]));

function Line({ label, children }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1 text-sm">
            <dt className="shrink-0 text-slate-400">{label}</dt>
            <dd className="min-w-0 break-words text-right text-slate-100">{children}</dd>
        </div>
    );
}

/**
 * Decision panel for one pending void or refund (voidGet/refundGet, then approve or reject). Approving is
 * the point where the sale is actually reversed, so it happens at *this* browser's terminal, on the
 * approver's own open shift and fiscal day -- not the requester's -- and the panel says so. If the
 * request can no longer be carried out the server refuses and leaves it pending; the message says why
 * and the request can still be rejected.
 */
export default function ApprovalReviewPanel({ kind, summary, terminalState, onDone, onClose, onUnauthorized }) {
    const isVoid = kind === 'void';
    const base = isVoid ? '/api/v1/voids' : '/api/v1/refunds';
    const [detail, setDetail] = useState(null);
    const [sale, setSale] = useState(null);
    const [loadFailure, setLoadFailure] = useState(null);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState(null);
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const approveKey = useRef(crypto.randomUUID());
    const rejectKey = useRef(crypto.randomUUID());

    useEffect(() => {
        let cancelled = false;
        request(`${base}/${summary.id}`).then(async (response) => {
            if (cancelled) {
                return;
            }
            if (!response.ok) {
                setLoadFailure(response);
                return;
            }
            setDetail(response.body);
            if (!isVoid) {
                const saleResponse = await request(`/api/v1/sales/${response.body.sale_id}`);
                if (!cancelled && saleResponse.ok) {
                    setSale(saleResponse.body);
                }
            }
        });
        return () => {
            cancelled = true;
        };
    }, [base, summary.id, isVoid]);

    const pending = detail?.status === 'REQUESTED';
    const canApprove = terminalState === 'enrolled' || terminalState === 'unknown';
    const nameOf = (saleItemId) => sale?.items.find((item) => item.id === saleItemId)?.product_name_snapshot ?? `Line ${shortId(saleItemId)}`;

    async function decide(path, body, keyRef, fallback) {
        setBusy(true);
        setFormError(null);
        const result = await request(`${base}/${summary.id}/${path}`, { method: 'POST', body, headers: { 'Idempotency-Key': keyRef.current } });
        setBusy(false);
        if (result.ok) {
            onDone(result.body, path);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        if (result.status === 0) {
            setFormError('The connection dropped, so this may not have been recorded. Press the button again — it will not be applied twice.');
            return;
        }
        keyRef.current = crypto.randomUUID();
        const serverErrors = fieldErrors(result);
        if (serverErrors.reason) {
            setReasonError(serverErrors.reason);
            return;
        }
        const code = result.body?.error?.code;
        const stillPending = ['SALE_NOT_VOIDABLE', 'SALE_ALREADY_VOIDED', 'REFUND_EXCEEDS_REMAINING_QUANTITY', 'REFUND_EXCEEDS_REMAINING_AMOUNT', 'REFUND_SETTLEMENT_MISMATCH', 'REFUND_NOT_ALLOWED', 'SHIFT_NOT_OPEN', 'FISCAL_DAY_CLOSED'].includes(code);
        const message = reversalMessage(result, fallback);
        setFormError(stillPending && path === 'approve' ? `${message} The request is still waiting; you can reject it if it should not go ahead.` : message);
    }

    function reject(event) {
        event.preventDefault();
        if (reason.trim() === '') {
            setReasonError('Say why this is being turned down.');
            return;
        }
        decide('reject', { reason: reason.trim() }, rejectKey, 'The request could not be rejected.');
    }

    const footer = pending ? (
        <div className="flex items-center justify-between gap-2 border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            {rejecting ? (
                <>
                    <button type="button" onClick={() => setRejecting(false)} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                        Back
                    </button>
                    <button
                        type="submit"
                        form="reject_form"
                        disabled={busy}
                        className="min-h-11 rounded-md border border-rose-600 bg-rose-950/60 px-5 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-600 hover:text-white disabled:opacity-50 lg:min-h-0"
                    >
                        {busy ? 'Working…' : 'Reject request'}
                    </button>
                </>
            ) : (
                <>
                    <button
                        type="button"
                        onClick={() => setRejecting(true)}
                        className="min-h-11 rounded-md border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0"
                    >
                        Reject…
                    </button>
                    <button
                        type="button"
                        disabled={busy || !canApprove}
                        onClick={() => decide('approve', undefined, approveKey, 'The request could not be approved.')}
                        className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                    >
                        {busy ? 'Working…' : isVoid ? 'Approve void' : 'Approve refund'}
                    </button>
                </>
            )}
        </div>
    ) : null;

    return (
        <SlideOver titleId="approval_panel_title" title={isVoid ? 'Void request' : 'Refund request'} badge={detail ? detail.status : null} onClose={onClose} footer={footer}>
            <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {loadFailure && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {loadFailure.status === 404 ? 'This request could not be found.' : failureMessage(loadFailure, 'The request could not be loaded.')}
                    </p>
                )}
                {!detail && !loadFailure && <div className="h-32 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading request" />}
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}

                {detail && (
                    <>
                        <dl className="divide-y divide-slate-800/70 rounded-md border border-slate-800 bg-slate-950/60 px-3">
                            <Line label="Sale">
                                <Link to={`/admin/sales/${detail.sale.id}`} className="font-mono text-emerald-400 hover:underline">
                                    Invoice {detail.sale.invoice_number ?? '—'}
                                </Link>{' '}
                                <StatusBadge status={detail.sale.status} />
                            </Line>
                            <Line label="Sale total">
                                <span className="font-mono tabular-nums">{formatMoney(detail.sale.grand_total)}</span>
                            </Line>
                            {!isVoid && (
                                <Line label="Refund total">
                                    <span className="font-mono tabular-nums">{formatMoney(detail.refund_total)}</span>
                                </Line>
                            )}
                            <Line label="Requested">
                                <span className="font-mono text-[12px]">{formatDateTime(detail.requested_at)}</span> by <span className="font-mono text-[12px]">{shortId(detail.requested_by)}</span>
                            </Line>
                            <Line label="Reason">{detail.reason}</Line>
                            {!pending && detail.resolved_at && (
                                <Line label="Decided">
                                    <span className="font-mono text-[12px]">{formatDateTime(detail.resolved_at)}</span>
                                    {detail.approved_by ? (
                                        <>
                                            {' '}
                                            by <span className="font-mono text-[12px]">{shortId(detail.approved_by)}</span>
                                        </>
                                    ) : null}
                                </Line>
                            )}
                        </dl>

                        {isVoid ? (
                            <p className="text-xs text-slate-400">
                                Approving cancels the whole sale and puts every item back in stock. It only works while the sale&apos;s fiscal day is still open.
                            </p>
                        ) : (
                            <>
                                <div>
                                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-400">Items being returned</h3>
                                    <ul className="space-y-2">
                                        {detail.items.map((item) => (
                                            <li key={item.sale_item_id} className="flex items-start justify-between gap-3 rounded-md border border-slate-800 px-3 py-2 text-sm">
                                                <div className="min-w-0">
                                                    <p className="break-words text-slate-100">{nameOf(item.sale_item_id)}</p>
                                                    <p className="font-mono text-[11px] text-slate-500">
                                                        {trimQuantity(item.quantity_returned)} returned &middot; {DISPOSITION_LABEL[item.disposition] ?? item.disposition}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 font-mono tabular-nums text-slate-100">{formatMoney(item.unit_refund_amount)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                <div>
                                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-400">Money returned</h3>
                                    <ul className="space-y-1">
                                        {detail.settlements.map((settlement, index) => (
                                            <li key={settlement.id ?? index} className="flex justify-between text-sm">
                                                <span className="text-slate-300">
                                                    {settlement.payment_method}
                                                    {settlement.external_reference ? ` · ${settlement.external_reference}` : ''}
                                                </span>
                                                <span className="font-mono tabular-nums text-slate-100">{formatMoney(settlement.amount)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </>
                        )}

                        {pending && (
                            <p className="rounded-md border border-slate-800 bg-slate-950/60 px-3 py-2 text-xs text-slate-400">
                                Approving happens at this terminal, on your own open shift and fiscal day, not the requester&apos;s.
                                {terminalState === 'not-enrolled' && ' This browser is not enrolled as a terminal, so it can reject a request but not approve one.'}
                            </p>
                        )}

                        {pending && rejecting && (
                            <form id="reject_form" onSubmit={reject} noValidate>
                                <label htmlFor="reject_reason" className="mb-1 block text-xs text-slate-400">
                                    Why is this being turned down? <span className="text-emerald-400">*</span>
                                </label>
                                <textarea
                                    id="reject_reason"
                                    rows={3}
                                    maxLength={255}
                                    autoFocus
                                    value={reason}
                                    aria-invalid={Boolean(reasonError)}
                                    onChange={(event) => {
                                        rejectKey.current = crypto.randomUUID();
                                        setReason(event.target.value);
                                        setReasonError(null);
                                    }}
                                    className={`w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:outline-none focus:ring-1 ${
                                        reasonError ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                                    }`}
                                />
                                {reasonError && (
                                    <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                                        &#9888; {reasonError}
                                    </p>
                                )}
                            </form>
                        )}
                    </>
                )}
            </div>
        </SlideOver>
    );
}

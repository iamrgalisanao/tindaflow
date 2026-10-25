import { useEffect, useRef, useState } from 'react';
import SlideOver from '../SlideOver';
import { failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime } from '../reports/formatters';
import { reversalMessage } from './salesParts';

/**
 * Slide-over for saleSlipGet and saleSlipReprint, the slip-mode twin of InvoicePanel. It opens on the plain original (a read, recorded
 * nowhere). "Print a copy" is the reprint operation: it records that a copy was produced -- no new slip number, no change to the sale --
 * and prints the returned slip, which carries a visible REPRINT/COPY mark. Every copy printed from here is therefore its own audited
 * event; there is deliberately no way to print the unmarked original again. One Idempotency-Key per print attempt: reused when the
 * same attempt is retried after a dropped connection, so a retry can never record a second copy.
 */
export default function SlipPanel({ slipId, slipNumber, canPrint, onClose, onUnauthorized }) {
    const [doc, setDoc] = useState(null);
    const [loadFailure, setLoadFailure] = useState(null);
    const [note, setNote] = useState(null);
    const [formError, setFormError] = useState(null);
    const [printing, setPrinting] = useState(false);
    const frameRef = useRef(null);
    const printWhenLoaded = useRef(false);
    const keyRef = useRef(crypto.randomUUID());

    useEffect(() => {
        let cancelled = false;
        request(`/api/v1/sale-slips/${slipId}`).then((response) => {
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setDoc(response.body.render_html);
            } else {
                setLoadFailure(response);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [slipId]);

    function frameLoaded() {
        if (printWhenLoaded.current) {
            printWhenLoaded.current = false;
            frameRef.current?.contentWindow?.focus();
            frameRef.current?.contentWindow?.print();
        }
    }

    async function printCopy() {
        setPrinting(true);
        setFormError(null);
        const result = await request(`/api/v1/sale-slips/${slipId}/reprints`, {
            method: 'POST',
            headers: { 'Idempotency-Key': keyRef.current },
        });
        setPrinting(false);
        if (result.ok) {
            keyRef.current = crypto.randomUUID(); // the next print is a new copy, not a retry
            setNote(`Copy recorded ${formatDateTime(result.body.reprinted_at)}. No new slip number was issued.`);
            printWhenLoaded.current = true;
            setDoc(result.body.slip.render_html);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        if (result.status === 0) {
            setFormError('The connection dropped, so the copy may not have been recorded. Press Print again — it will not be recorded twice.');
            return;
        }
        keyRef.current = crypto.randomUUID();
        setFormError(reversalMessage(result, 'The copy could not be recorded.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Close
            </button>
            <button
                type="button"
                disabled={printing || !canPrint || doc === null}
                onClick={printCopy}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
            >
                {printing ? 'Recording…' : 'Print a copy'}
            </button>
        </div>
    );

    return (
        <SlideOver titleId="slip_panel_title" title="Slip" badge={slipNumber ? `#${slipNumber}` : null} onClose={onClose} footer={footer}>
            <div className="flex-1 space-y-3 overflow-y-auto px-5 py-4">
                {loadFailure && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {loadFailure.status === 401 ? 'Your session has expired. Sign in again to continue.' : loadFailure.status === 404 ? 'This slip could not be found.' : failureMessage(loadFailure, 'The slip could not be loaded.')}
                    </p>
                )}
                {formError && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {formError}
                    </p>
                )}
                {note && (
                    <p role="status" className="rounded-md border border-emerald-800 bg-emerald-950/30 px-3 py-2 text-sm text-slate-100">
                        {note}
                    </p>
                )}
                {!canPrint && (
                    <p className="text-xs text-amber-400">This browser is not enrolled as a terminal, so it can show the slip but not record a printed copy.</p>
                )}

                {doc === null && !loadFailure && <div className="h-80 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading slip" />}
                {doc !== null && (
                    <iframe
                        ref={frameRef}
                        title={`Slip ${slipNumber ?? ''}`}
                        srcDoc={doc}
                        sandbox="allow-same-origin allow-modals"
                        onLoad={frameLoaded}
                        className="h-[32rem] w-full rounded-md border border-slate-700 bg-white"
                    />
                )}
                <p className="text-[11px] text-slate-500">
                    A printed copy is marked REPRINT &mdash; COPY and is recorded in the audit log. The original slip, its number and the sale are never changed.
                </p>
            </div>
        </SlideOver>
    );
}

import { useState } from 'react';
import OverrideApproval from './OverrideApproval';
import { requestItems } from './mealMenu';
import { stockGrantFromResponse, stockOverrideFailureText } from './stock';

/**
 * A sale the server refused for stock (ADR-017 slice A, INSUFFICIENT_STOCK). Nothing was sold or charged. It lists EVERY short line with
 * how much was asked for and how much is there, and offers the three ways on: fix the cart, remove an item, or (when the store allows it)
 * ask a manager to approve THIS one sale -- picked by name, the manager types their own code on the keypad, and the till then sends the
 * sale again with the single-use approval (held by the register for this sale only; `onApproved` hands it up). The till never retries a
 * refusal by itself.
 */
export default function StockRefusalPanel({ refusal, cart, busy, onApproved, onFix, onRemoveItem }) {
    const [asking, setAsking] = useState(false);
    const lines = refusal.shortfalls;
    // The approval is for exactly this cart: its product lines and its meal plates (their chosen daily dishes draw portions from the dishes' pools).
    const items = cart.flatMap((line) => requestItems(line, 0n));

    return (
        <section role="alert" aria-label="Not enough stock" className="mx-4 mt-3 rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-200">
            <p className="font-semibold">Not enough stock, so this sale was not made. Nothing was charged.</p>
            <ul aria-label="Items short of stock" className="mt-2 divide-y divide-red-500/20 rounded border border-red-500/20 bg-slate-950/40">
                {lines.map((line) => (
                    <li key={`${line.productId}:${line.stockDate ?? ''}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span className="min-w-0 break-words font-medium text-red-100">{line.name}</span>
                        <span className="font-mono text-xs text-red-200">
                            {line.kind === 'DISH'
                                ? `needs ${line.required} portions, ${Number(line.available) > 0 ? `only ${line.available} left today` : 'none left today'}`
                                : `needs ${line.required}, ${Number(line.available) > 0 ? `only ${line.available} in stock` : 'no stock'}`}
                        </span>
                        <button
                            type="button"
                            onClick={() => onRemoveItem(line)}
                            disabled={busy}
                            aria-label={`Remove ${line.name} from the cart`}
                            className="min-h-11 rounded border border-red-400/60 px-3 text-xs font-bold uppercase tracking-wide text-red-100 hover:bg-red-500/20 disabled:opacity-50"
                        >
                            Remove item
                        </button>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-red-300/90">
                Change the quantity or remove the item, or ask a manager to enter the stock{refusal.overrideAvailable ? ' or to approve this sale' : ''}.
            </p>

            <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" autoFocus onClick={onFix} disabled={busy} className="min-h-12 rounded-lg border border-slate-500 px-5 text-sm font-bold uppercase tracking-wide text-slate-100 hover:bg-slate-800 disabled:opacity-50">
                    Fix the cart
                </button>
                {refusal.overrideAvailable && !asking && (
                    <button type="button" onClick={() => setAsking(true)} disabled={busy} className="min-h-12 rounded-lg border border-amber-400 px-5 text-sm font-bold text-amber-200 hover:bg-amber-400/10 disabled:opacity-50">
                        Ask a manager to approve
                    </button>
                )}
            </div>

            {asking && (
                <OverrideApproval
                    busy={busy}
                    approversUrl="/api/v1/stock-override-approvers"
                    grantUrl="/api/v1/stock-overrides"
                    grantBody={(approverId, code) => ({ approver_id: approverId, code, items })}
                    intro="One approval covers this sale only, with the items short of stock shown above. The stock goes below zero for them. The manager types their own code; you do not see it."
                    confirmLabel="Approve and sell"
                    busyLabel="Selling…"
                    failureText={stockOverrideFailureText}
                    toGrant={stockGrantFromResponse}
                    onApproved={onApproved}
                    onCancel={() => setAsking(false)}
                />
            )}
        </section>
    );
}

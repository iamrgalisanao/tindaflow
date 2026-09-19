import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import { RETURN_KEY, request } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';

export const fieldClass =
    'min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0';

/** Loads one API path (or nothing while `path` is null); a slower earlier response never overwrites a newer one. */
export function useLoad(path) {
    const [data, setData] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(path !== null);
    const [reloadKey, setReloadKey] = useState(0);
    const seq = useRef(0);

    useEffect(() => {
        if (path === null) {
            return;
        }
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        request(path).then((response) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setData(response.body);
            } else {
                setData(null);
                setFailure(response);
            }
        });
    }, [path, reloadKey]);

    const reload = useCallback(() => setReloadKey((key) => key + 1), []);

    return { data, failure, loading, reload };
}

/** Sends the user back to the sign-in screen and returns them to this page afterwards. */
export function useSignIn() {
    const { setUser } = useAuth();

    return useCallback(() => {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }, [setUser]);
}

const STATUS_TONE = {
    OPEN: 'border-sky-700/70 text-sky-300',
    CLOSED: 'border-slate-600 text-slate-300',
};

export function DayStatusBadge({ status }) {
    return <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${STATUS_TONE[status] ?? STATUS_TONE.CLOSED}`}>{status}</span>;
}

/** Variance is declared cash minus expected cash: negative is short, positive is over. Null while the shift is open. */
export function Variance({ value }) {
    if (value === null || value === undefined) {
        return <span className="text-slate-600">—</span>;
    }
    const cents = Number(value);
    if (cents === 0) {
        return <span className="text-emerald-400">Balanced</span>;
    }
    return cents < 0 ? (
        <span className="text-rose-400">Short {formatMoney(String(value).replace('-', ''))}</span>
    ) : (
        <span className="text-amber-400">Over {formatMoney(value)}</span>
    );
}

export function Money({ value }) {
    return value === null || value === undefined ? <span className="text-slate-600">—</span> : formatMoney(value);
}

/** A label/value grid for a record's headline facts. `items` are [label, node] pairs. */
export function Facts({ items }) {
    return (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
            {items.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-[11px] uppercase tracking-wide text-slate-500">{label}</dt>
                    <dd className="break-words font-mono text-sm tabular-nums text-slate-100">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function Panel({ title, children, note }) {
    return (
        <section className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-4">
            <h3 className="text-sm font-semibold text-slate-100">{title}</h3>
            {note && <p className="mt-0.5 text-xs text-slate-500">{note}</p>}
            <div className="mt-3">{children}</div>
        </section>
    );
}

/** Payment method -> amount, as a small table. */
export function PaymentBreakdown({ breakdown }) {
    const rows = Object.entries(breakdown ?? {});
    if (rows.length === 0) {
        return <p className="text-sm text-slate-500">No payments were taken.</p>;
    }
    return (
        <table className="w-full max-w-sm text-left text-sm">
            <tbody className="divide-y divide-slate-800/70">
                {rows.map(([method, amount]) => (
                    <tr key={method}>
                        <th scope="row" className="py-1 pr-4 font-normal text-slate-400">
                            {method}
                        </th>
                        <td className="py-1 text-right font-mono tabular-nums text-slate-100">{formatMoney(amount)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** The (X-reading) figures a shift's readings carry. */
export function XReadingTotals({ totals }) {
    return (
        <div className="space-y-3">
            <Facts
                items={[
                    ['Opening cash', formatMoney(totals.opening_cash)],
                    ['Cash sales', formatMoney(totals.cash_sales)],
                    ['Non-cash sales', formatMoney(totals.non_cash_sales)],
                    ['Refunds', formatMoney(totals.refunds_total)],
                    ['Cash in', formatMoney(totals.cash_in_total)],
                    ['Cash out', formatMoney(totals.cash_out_total)],
                    ['Expected cash', formatMoney(totals.expected_cash)],
                    ['Variance', <Variance key="v" value={totals.variance} />],
                    ['Transactions', String(totals.transaction_count)],
                ]}
            />
            <div>
                <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">Payments by method</p>
                <PaymentBreakdown breakdown={totals.payment_breakdown} />
            </div>
        </div>
    );
}

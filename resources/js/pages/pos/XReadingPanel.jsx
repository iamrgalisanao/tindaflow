import { formatMoney } from '../admin/reports/formatters';

/**
 * The interim (X) reading, taken from the till itself (openapi.yaml shiftXReadingCreate).
 *
 * It is an accountability pull, not a close: it never changes the shift's status and never resets a
 * total, so it can be taken as often as the shift needs one. `variance` is always null here -- there
 * is no declared count to compare against until the shift is closed -- so it is not shown at all.
 *
 * Whoever takes the reading decides what it may show. XReadingResource blanks every cash-deriving
 * figure (and the CASH row of the payment breakdown) for an actor without REPORT_VIEW, so a cashier
 * cannot count their drawer against a number the screen just gave them. Those figures must therefore
 * read as deliberately withheld -- never as a zero, and never as an empty cell that looks like a bug.
 */

function Amount({ value, withheld }) {
    if (withheld) {
        return (
            <span className="font-mono text-[11px] uppercase tracking-wider text-slate-500" title="Hidden until the shift is closed">
                withheld
            </span>
        );
    }
    return <span className="font-mono tabular-nums text-slate-100">{value === null || value === undefined ? '—' : formatMoney(value)}</span>;
}

function Row({ label, value, withheld, indent }) {
    return (
        <div className={`flex items-baseline justify-between gap-4 py-1 ${indent ? 'pl-4' : ''}`}>
            <dt className={indent ? 'text-xs text-slate-500' : 'text-sm text-slate-400'}>{label}</dt>
            <dd className={indent ? 'text-xs' : 'text-sm'}>
                <Amount value={value} withheld={withheld} />
            </dd>
        </div>
    );
}

function readingTime(iso) {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? String(iso) : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'medium' });
}

export default function XReadingPanel({ readings, latest, cashVisible, busy, error, onTake }) {
    const totals = latest?.totals_snapshot ?? null;
    // Only the CASH row is ever removed; the other methods are present for everyone.
    const breakdown = Object.entries(totals?.payment_breakdown ?? {});

    return (
        <section className="space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-4 md:col-span-2">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-slate-100">X-reading</h2>
                    <p className="mt-0.5 text-xs text-slate-400">
                        A reading of the shift so far. It does not close the shift and changes nothing — take one whenever the count has to be accounted for.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onTake}
                    disabled={busy}
                    className="min-h-12 rounded bg-emerald-500 px-5 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-400"
                >
                    {busy ? 'Taking…' : 'Take X-reading'}
                </button>
            </div>

            {error && (
                <p role="alert" className="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">
                    {error}
                </p>
            )}

            {latest && (
                <div className="rounded-md border border-emerald-800/60 bg-slate-950 p-3">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 pb-2">
                        <p className="font-mono text-[11px] uppercase tracking-wider text-emerald-400">
                            Reading #{readings.length} · interim
                        </p>
                        <p className="font-mono text-[11px] text-slate-500">{readingTime(latest.generated_at)}</p>
                    </div>

                    <dl className="divide-y divide-slate-800/70">
                        <div className="flex items-baseline justify-between gap-4 py-1">
                            <dt className="text-sm text-slate-400">Transactions</dt>
                            <dd className="font-mono text-sm tabular-nums text-slate-100">{totals.transaction_count}</dd>
                        </div>
                        <Row label="Opening cash" value={totals.opening_cash} />
                        <Row label="Cash sales" value={totals.cash_sales} withheld={!cashVisible} />
                        <Row label="Non-cash sales" value={totals.non_cash_sales} />
                        {breakdown.map(([method, amount]) => (
                            <Row key={method} label={method} value={amount} indent />
                        ))}
                        {!cashVisible && <Row label="CASH" withheld indent />}
                        <Row label="Refunds" value={totals.refunds_total} withheld={!cashVisible} />
                        <Row label="Cash in" value={totals.cash_in_total} withheld={!cashVisible} />
                        <Row label="Cash out" value={totals.cash_out_total} withheld={!cashVisible} />
                        <div className="flex items-baseline justify-between gap-4 pt-2">
                            <dt className="text-sm font-semibold text-slate-100">Expected cash</dt>
                            <dd className="text-base font-bold">
                                <Amount value={totals.expected_cash} withheld={!cashVisible} />
                            </dd>
                        </div>
                    </dl>

                    {!cashVisible && (
                        <p className="mt-2 border-t border-slate-800 pt-2 text-xs text-amber-400">
                            Cash figures stay hidden while the shift is open, so your drawer count stays blind. They are worked out when you
                            close the shift and declare what you counted.
                        </p>
                    )}
                </div>
            )}

            {readings.length > 0 && (
                <div>
                    <p className="mb-1 font-mono text-[11px] uppercase tracking-wider text-slate-500">Readings this shift</p>
                    <ul className="space-y-0.5">
                        {readings.map((reading, index) => (
                            <li key={reading.id} className="flex justify-between gap-4 font-mono text-[11px] text-slate-400">
                                <span>#{index + 1}</span>
                                <span>{readingTime(reading.generated_at)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {readings.length === 0 && !latest && <p className="text-xs text-slate-500">No reading has been taken on this shift yet.</p>}
        </section>
    );
}

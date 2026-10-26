import { useState } from 'react';
import { formatDateTime, formatMoney, formatQuantity } from '../reports/formatters';
import { accountIds, formatWhen, kindLabel, negativeMoney, toggleAllIds, toggleId } from './billingConfig';

const TYPE_SHORT = { DIRECT: 'TSC', SUBCONTRACTOR: 'SUB' };

/**
 * One billing statement: the totals, what changed since the last saved run, then Account (employee) -> Meal type -> Item/dish with each
 * employee collapsed to a line of subtotals until opened, and the voids and refunds as their own Adjustments section. Read only;
 * the page around it owns saving, printing and exporting.
 */
export default function StatementView({ statement }) {
    const [expanded, setExpanded] = useState(() => new Set());
    const [shown, setShown] = useState(statement);

    // A new statement closes every account. Reset while rendering, not in an effect: an effect could run after the first click on a fast
    // machine and silently undo it (a flaky test showed it).
    if (shown !== statement) {
        setShown(statement);
        setExpanded(new Set());
    }

    const { totals, accounts, adjustments, comparison, criteria } = statement;
    const allOpen = accounts.length > 0 && accountIds(statement).every((id) => expanded.has(id));

    return (
        <div className="space-y-6">
            <section aria-label="Statement totals" className="rounded-lg border border-slate-800 bg-slate-900 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h3 className="text-base font-semibold text-slate-100">
                            {statement.party.name} · {criteria.period_label}
                        </h3>
                        <p className="mt-0.5 font-mono text-xs text-slate-500">
                            {criteria.from} to {criteria.to} · as of {formatWhen(criteria.as_of)}
                            {criteria.employee_type ? ` · ${TYPE_SHORT[criteria.employee_type]} only` : ''}
                            {criteria.employee_code ? ` · ${criteria.employee_code}` : ''}
                        </p>
                    </div>
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs ${statement.reconciliation.matches ? 'bg-emerald-950 text-emerald-300 ring-1 ring-emerald-800' : 'bg-rose-950 text-rose-300 ring-1 ring-rose-800'}`}
                    >
                        {statement.reconciliation.matches ? `Matches the credit ledger (${formatMoney(statement.reconciliation.ledger_net)})` : 'Does not match the ledger'}
                    </span>
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <Figure label="Sold" value={formatMoney(totals.sold_amount)} />
                    <Figure label="Voids and refunds" value={negativeMoney(totals.returned_amount)} />
                    <Figure label="Total due" value={formatMoney(totals.net_amount)} strong />
                    <Figure label="Employees" value={String(totals.employee_count)} />
                    <Figure label="Sales" value={String(totals.sale_count)} />
                </dl>
                {Object.keys(totals.by_employee_type).length > 1 && (
                    <p className="mt-3 text-xs text-slate-400">
                        {Object.entries(totals.by_employee_type)
                            .map(([type, entry]) => `${TYPE_SHORT[type] ?? type}: ${entry.employee_count} employees, ${formatMoney(entry.net_amount)}`)
                            .join(' · ')}
                    </p>
                )}
            </section>

            <Comparison comparison={comparison} netNow={totals.net_amount} />

            <section aria-label="Accounts">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <h3 className="text-base font-semibold text-slate-100">Accounts</h3>
                    {accounts.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setExpanded(toggleAllIds(statement, expanded))}
                            className="min-h-11 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0"
                        >
                            {allOpen ? 'Collapse all' : 'Expand all'}
                        </button>
                    )}
                </div>
                {accounts.length === 0 ? (
                    <p className="rounded-lg border border-slate-800 bg-slate-900 p-4 text-sm text-slate-400">No credit sales in this period.</p>
                ) : (
                    <ul className="divide-y divide-slate-800 overflow-hidden rounded-lg border border-slate-800 bg-slate-900">
                        {accounts.map((account) => (
                            <AccountRow key={account.employee_id} account={account} open={expanded.has(account.employee_id)} onToggle={() => setExpanded((current) => toggleId(current, account.employee_id))} />
                        ))}
                        <li className="flex items-center justify-between gap-3 bg-slate-950 px-4 py-3 text-sm font-semibold text-slate-100">
                            <span>Grand total</span>
                            <span className="font-mono tabular-nums">{formatMoney(totals.net_amount)}</span>
                        </li>
                    </ul>
                )}
            </section>

            <Adjustments adjustments={adjustments} returned={totals.returned_amount} />
        </div>
    );
}

function Figure({ label, value, strong = false }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className={`font-mono tabular-nums ${strong ? 'text-lg font-semibold text-emerald-300' : 'text-base text-slate-100'}`}>{value}</dd>
        </div>
    );
}

function AccountRow({ account, open, onToggle }) {
    const panelId = `account-${account.employee_id}`;

    return (
        <li>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                aria-controls={panelId}
                className="grid min-h-11 w-full grid-cols-[1.25rem_1fr_auto] items-center gap-x-3 px-4 py-2 text-left hover:bg-slate-800/60 sm:grid-cols-[1.25rem_1fr_6rem_6rem_7rem]"
            >
                <span aria-hidden="true" className="text-slate-500">
                    {open ? '▾' : '▸'}
                </span>
                <span className="min-w-0">
                    <span className="font-mono text-sm text-emerald-300">{account.employee_code}</span> <span className="text-sm text-slate-100">{account.employee_name}</span>{' '}
                    <span className="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-slate-300">{TYPE_SHORT[account.employee_type] ?? account.employee_type}</span>
                </span>
                <span className="hidden text-right font-mono text-xs tabular-nums text-slate-400 sm:block">{formatMoney(account.sold_amount)}</span>
                <span className="hidden text-right font-mono text-xs tabular-nums text-amber-300 sm:block">
                    {account.returned_amount === '0.00' ? '' : negativeMoney(account.returned_amount)}
                </span>
                <span className="text-right font-mono text-sm font-semibold tabular-nums text-slate-100">{formatMoney(account.net_amount)}</span>
            </button>
            {open && (
                <div id={panelId} className="space-y-4 bg-slate-950/60 px-4 py-3">
                    {account.meal_types.map((type) => (
                        <div key={type.meal_type}>
                            <div className="flex items-baseline justify-between gap-3 border-b border-slate-800 pb-1">
                                <h4 className="text-sm font-medium text-slate-200">{type.meal_type}</h4>
                                <span className="font-mono text-xs tabular-nums text-slate-300">
                                    {formatMoney(type.net_amount)}
                                    {type.returned_amount !== '0.00' && <span className="ml-2 text-amber-300">({negativeMoney(type.returned_amount)} returned)</span>}
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="mt-1 w-full min-w-[32rem] text-xs">
                                    <thead className="text-slate-500">
                                        <tr>
                                            <th scope="col" className="py-1 text-left font-normal">
                                                Item
                                            </th>
                                            <th scope="col" className="py-1 text-right font-normal">
                                                Qty
                                            </th>
                                            <th scope="col" className="py-1 text-right font-normal">
                                                Amount
                                            </th>
                                            <th scope="col" className="py-1 text-right font-normal">
                                                Returned
                                            </th>
                                            <th scope="col" className="py-1 text-right font-normal">
                                                Net
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {type.items.map((item) => (
                                            <tr key={`${item.kind}-${item.item}`} className="border-t border-slate-900 text-slate-300">
                                                <td className="py-1 pr-2">
                                                    {item.item} {item.kind === 'ADDON' && <span className="text-slate-500">({kindLabel(item.kind).toLowerCase()})</span>}
                                                </td>
                                                <td className="py-1 text-right font-mono tabular-nums">{formatQuantity(item.quantity_net)}</td>
                                                <td className="py-1 text-right font-mono tabular-nums">{formatMoney(item.sold_amount)}</td>
                                                <td className="py-1 text-right font-mono tabular-nums text-amber-300">{item.returned_amount === '0.00' ? '' : negativeMoney(item.returned_amount)}</td>
                                                <td className="py-1 text-right font-mono tabular-nums text-slate-100">{formatMoney(item.net_amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                    <p className="flex justify-between text-sm font-semibold text-slate-100">
                        <span>Total for {account.employee_name}</span>
                        <span className="font-mono tabular-nums">{formatMoney(account.net_amount)}</span>
                    </p>
                </div>
            )}
        </li>
    );
}

function Adjustments({ adjustments, returned }) {
    return (
        <section aria-label="Adjustments" className="rounded-lg border border-amber-900/60 bg-slate-900 p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="text-base font-semibold text-amber-200">Adjustments (voids and refunds)</h3>
                {adjustments.length > 0 && (
                    <span className="font-mono text-sm tabular-nums text-amber-300">
                        {adjustments.length} · {negativeMoney(returned)}
                    </span>
                )}
            </div>
            <p className="mt-1 text-xs text-slate-400">A void or refund takes money off the period of the ORIGINAL sale, however late it was recorded.</p>
            {adjustments.length === 0 ? (
                <p className="mt-3 text-sm text-slate-400">No voids or refunds in this period.</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-xs">
                        <thead className="text-slate-500">
                            <tr>
                                <th scope="col" className="py-1 text-left font-normal">Recorded</th>
                                <th scope="col" className="py-1 text-left font-normal">Type</th>
                                <th scope="col" className="py-1 text-left font-normal">Employee</th>
                                <th scope="col" className="py-1 text-left font-normal">Sold on</th>
                                <th scope="col" className="py-1 text-left font-normal">Document</th>
                                <th scope="col" className="py-1 text-left font-normal">Reason</th>
                                <th scope="col" className="py-1 text-right font-normal">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {adjustments.map((adjustment) => (
                                <tr key={adjustment.entry_id} className="border-t border-slate-800 align-top text-slate-300">
                                    <td className="py-1.5 pr-2 font-mono">{formatDateTime(adjustment.recorded_at)}</td>
                                    <td className="py-1.5 pr-2">
                                        <span className="rounded bg-amber-950 px-1.5 py-0.5 font-mono text-[10px] text-amber-300">{adjustment.type}</span>
                                    </td>
                                    <td className="py-1.5 pr-2">
                                        {adjustment.employee_code} {adjustment.employee_name}
                                        <ul className="mt-0.5 text-slate-500">
                                            {adjustment.lines.map((line, index) => (
                                                <li key={`${line.item}-${index}`}>
                                                    {formatQuantity(line.quantity)} × {line.item} ({line.meal_type})
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="py-1.5 pr-2 font-mono">{adjustment.sold_on}</td>
                                    <td className="py-1.5 pr-2 font-mono">{adjustment.document_reference ?? ''}</td>
                                    <td className="py-1.5 pr-2">{adjustment.reason ?? ''}</td>
                                    <td className="py-1.5 text-right font-mono tabular-nums text-amber-300">{negativeMoney(adjustment.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function Comparison({ comparison, netNow }) {
    if (!comparison) {
        return null;
    }
    const { previous_run: previous } = comparison;

    if (!comparison.changed) {
        return (
            <p role="status" className="rounded-lg border border-slate-800 bg-slate-900 px-4 py-3 text-sm text-slate-400">
                Same as billing run {previous.run_number} (saved {formatWhen(previous.generated_at)}): nothing has changed since.
            </p>
        );
    }

    return (
        <section role="status" aria-label="Changes since the last saved run" className="rounded-lg border border-amber-700 bg-amber-950/40 px-4 py-3 text-sm text-amber-100">
            <p className="font-semibold">
                This period has changed since billing run {previous.run_number} (saved {formatWhen(previous.generated_at)}).
            </p>
            <p className="mt-1">
                Total due was {formatMoney(previous.net_amount)}, now <strong>{formatMoney(netNow)}</strong> (change {formatMoney(comparison.net_change)}).
            </p>
            {comparison.added_adjustments.length > 0 && (
                <ul className="mt-2 list-disc pl-5">
                    {comparison.added_adjustments.map((adjustment) => (
                        <li key={adjustment.entry_id}>
                            {adjustment.type === 'VOID' ? 'Void' : 'Refund'} of {adjustment.employee_code} {adjustment.employee_name}, {negativeMoney(adjustment.amount)} (sold {adjustment.sold_on}, recorded {formatWhen(adjustment.recorded_at)})
                        </li>
                    ))}
                </ul>
            )}
            {comparison.removed_adjustments.length > 0 && <p className="mt-2">{comparison.removed_adjustments.length} adjustment(s) of that run are not counted at this as-of date.</p>}
        </section>
    );
}

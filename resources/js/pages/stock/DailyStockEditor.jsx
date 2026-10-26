import { useCallback, useEffect, useRef, useState } from 'react';
import { request } from '../admin/catalog/catalogApi';
import OverrideApproval from '../pos/OverrideApproval';
import { approvalFailureText, buildLines, copyYesterday, groupRows, parseQuantity, rowKey, saveFailureText } from './dailyStock';

const input = 'min-h-11 w-24 rounded-md border border-slate-700 bg-slate-950 px-2 text-right font-mono text-sm tabular-nums text-slate-100 focus:border-emerald-500 focus:outline-none';

const newKey = () => (globalThis.crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

/**
 * "Today's stock" (ADR-017 slice B): the grid where the day's cooked portions are entered, one row per dish and per daily product, with what is
 * cooked, sold and left. Used on the till and in the back office.
 *
 * `canEnterDirectly` is true for a manager or administrator (STOCK_ADJUST): Save writes at once. A cashier cannot: Save asks a manager to approve
 * with their own code on the keypad (the cashier never sees it), and the single-use approval goes with the save. The server enforces this; the
 * screen only mirrors it. "Copy yesterday's quantities" only fills the boxes; nothing is saved until Save. One Idempotency-Key per save attempt, so
 * a retry after a dropped connection is never counted twice.
 */
export default function DailyStockEditor({ canEnterDirectly, onSaved = () => {} }) {
    const [grid, setGrid] = useState(null); // null = loading
    const [loadError, setLoadError] = useState(null);
    const [cooked, setCooked] = useState({});
    const [add, setAdd] = useState({});
    const [reason, setReason] = useState('');
    const [rowErrors, setRowErrors] = useState({});
    const [message, setMessage] = useState(null); // { tone, text }
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const keyRef = useRef(newKey());

    const load = useCallback(async () => {
        const result = await request('/api/v1/daily-stock');
        if (result.ok) {
            setGrid(result.body);
            setLoadError(null);
        } else {
            setLoadError(result.status === 401 ? 'Your session expired. Sign in again.' : "Today's stock could not be loaded. Try again.");
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const rows = grid?.rows ?? [];
    const built = buildLines(rows, cooked, add);
    const needsReason = built.lowers;
    const canSave = built.lines.length > 0 && Object.keys(built.errors).length === 0 && (!needsReason || reason.trim() !== '') && !busy;

    async function post(token, approverName) {
        setBusy(true);
        setMessage(null);
        const body = { lines: built.lines, ...(needsReason ? { reason: reason.trim() } : {}), ...(token ? { stock_entry_token: token } : {}) };
        const result = await request('/api/v1/daily-stock/entries', { method: 'POST', body, headers: { 'Idempotency-Key': keyRef.current } });
        setBusy(false);
        setAsking(false);

        if (result.ok) {
            keyRef.current = newKey();
            setGrid({ ...grid, ...result.body.grid });
            setCooked({});
            setAdd({});
            setReason('');
            setRowErrors({});
            setMessage({ tone: 'ok', text: approverName ? `Saved. Approved by ${approverName}.` : 'Saved.' });
            onSaved();

            return;
        }
        // A refused save leaves the boxes as they are, and the same key is reused only for the very same request (a dropped connection).
        if (result.status === 422 && result.body?.error?.code === 'DAILY_STOCK_BELOW_SOLD') {
            keyRef.current = newKey();
        }
        setMessage({ tone: 'error', text: saveFailureText(result) });
    }

    function save() {
        if (!canSave) {
            return;
        }
        setRowErrors(built.errors);
        if (canEnterDirectly) {
            post(null, null);
        } else {
            setAsking(true);
        }
    }

    if (grid === null) {
        return (
            <p role={loadError ? 'alert' : 'status'} className={`py-8 text-center text-sm ${loadError ? 'text-red-300' : 'text-slate-400'}`}>
                {loadError ?? "Loading today's stock…"}
            </p>
        );
    }

    return (
        <section aria-label="Today's stock" className="space-y-4 text-slate-100">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold">Today&apos;s stock</h2>
                    <p className="text-xs text-slate-400">
                        Stock day <span className="font-mono">{grid.stock_date}</span>. Enter how many portions were cooked. {canEnterDirectly ? '' : 'A manager approves the save with their code.'}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => {
                        setCooked((prior) => ({ ...prior, ...copyYesterday(rows) }));
                        setMessage({ tone: 'ok', text: "Yesterday's quantities are filled in. Nothing is saved until you press Save." });
                    }}
                    className="min-h-11 rounded border border-slate-600 px-4 text-xs font-bold uppercase tracking-wide text-slate-200 hover:bg-slate-800"
                >
                    Copy yesterday&apos;s quantities
                </button>
            </div>

            {rows.length === 0 && (
                <p role="status" className="rounded border border-slate-700 bg-slate-900 px-4 py-6 text-center text-sm text-slate-400">
                    No dish or product counts daily stock yet. A manager switches it on under Meal menu &gt; Dishes (and on a product).
                </p>
            )}

            {groupRows(rows).map((group) => (
                <div key={group.name} className="overflow-x-auto rounded-lg border border-slate-800">
                    <table className="w-full min-w-[34rem] text-sm">
                        <caption className="bg-slate-900 px-3 py-2 text-left font-mono text-[11px] font-bold uppercase tracking-widest text-slate-400">{group.name}</caption>
                        <thead>
                            <tr className="border-y border-slate-800 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                <th scope="col" className="px-3 py-2">Item</th>
                                <th scope="col" className="px-3 py-2 text-right">Cooked today</th>
                                <th scope="col" className="px-3 py-2 text-right">Sold</th>
                                <th scope="col" className="px-3 py-2 text-right">Left</th>
                                <th scope="col" className="px-3 py-2 text-right">Add portions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800">
                            {group.rows.map((row) => {
                                const key = rowKey(row);
                                const left = parseQuantity(row.remaining) ?? 0n;
                                const yesterday = row.yesterday_cooked;
                                return (
                                    <tr key={key}>
                                        <th scope="row" className="px-3 py-2 text-left font-medium">
                                            {row.name}
                                            {Number(yesterday) > 0 && <span className="block font-mono text-[11px] font-normal text-slate-500">yesterday {Number(yesterday)}</span>}
                                            {rowErrors[key] && (
                                                <span role="alert" className="block text-[11px] font-normal text-rose-400">
                                                    {rowErrors[key]}
                                                </span>
                                            )}
                                        </th>
                                        <td className="px-3 py-2 text-right">
                                            <input
                                                aria-label={`Cooked today, ${row.name}`}
                                                inputMode="decimal"
                                                value={cooked[key] ?? ''}
                                                placeholder={Number(row.cooked) > 0 ? String(Number(row.cooked)) : '0'}
                                                onChange={(event) => setCooked((prior) => ({ ...prior, [key]: event.target.value }))}
                                                className={input}
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono tabular-nums text-slate-300">{Number(row.sold)}</td>
                                        <td className={`px-3 py-2 text-right font-mono font-bold tabular-nums ${left < 0n ? 'text-red-300' : left === 0n ? 'text-amber-300' : 'text-emerald-300'}`}>{Number(row.remaining)}</td>
                                        <td className="px-3 py-2 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                {[5, 10].map((n) => (
                                                    <button
                                                        key={n}
                                                        type="button"
                                                        aria-label={`Add ${n} to ${row.name}`}
                                                        onClick={() => setAdd((prior) => ({ ...prior, [key]: String((parseQuantity(prior[key] ?? '') ?? 0n) / 1000n + BigInt(n)) }))}
                                                        className="min-h-11 rounded border border-slate-700 px-2 font-mono text-xs text-slate-300 hover:bg-slate-800"
                                                    >
                                                        +{n}
                                                    </button>
                                                ))}
                                                <input
                                                    aria-label={`Add portions, ${row.name}`}
                                                    inputMode="decimal"
                                                    value={add[key] ?? ''}
                                                    onChange={(event) => setAdd((prior) => ({ ...prior, [key]: event.target.value }))}
                                                    className={input}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ))}

            {needsReason && (
                <div>
                    <label htmlFor="daily_stock_reason" className="mb-1 block text-xs text-slate-400">
                        Why is a quantity being lowered? <span className="text-emerald-400">*</span>
                    </label>
                    <input
                        id="daily_stock_reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                    />
                </div>
            )}

            {message && (
                <p role={message.tone === 'error' ? 'alert' : 'status'} className={`rounded px-3 py-2 text-sm ${message.tone === 'error' ? 'bg-red-500/10 text-red-300' : 'bg-emerald-500/10 text-emerald-300'}`}>
                    {message.text}
                </p>
            )}

            <button
                type="button"
                onClick={save}
                disabled={!canSave}
                className="min-h-12 w-full rounded-lg bg-emerald-500 px-5 text-base font-bold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500 sm:w-auto"
            >
                {busy ? 'Saving…' : canEnterDirectly ? 'Save stock' : 'Save stock (manager approves)'}
            </button>

            {asking && (
                <OverrideApproval
                    busy={busy}
                    approversUrl="/api/v1/daily-stock/approvers"
                    grantUrl="/api/v1/daily-stock/approvals"
                    grantBody={(approverId, code) => ({ approver_id: approverId, code })}
                    intro="A manager approves this one save with their own code. The approval lasts a few minutes and is used once; you do not see the code."
                    confirmLabel="Approve and save"
                    busyLabel="Saving…"
                    failureText={approvalFailureText}
                    toGrant={(body) => ({ token: body.token, approverName: body.approver?.name ?? '' })}
                    onApproved={(grant) => post(grant.token, grant.approverName)}
                    onCancel={() => setAsking(false)}
                />
            )}
        </section>
    );
}

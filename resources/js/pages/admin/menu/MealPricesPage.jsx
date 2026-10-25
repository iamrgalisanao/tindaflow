import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fetchAll, fieldErrors, normalizeMoney, request } from '../catalog/catalogApi';
import { effectiveFromPayload, formatPeso, historyQuery, priceDiffers, rowKey, whenLabel } from './menuConfig';
import { FieldError, Loading, inputClass, linkButton, primaryButton, secondaryButton } from './menuParts';

/** One grid row: the price in force, the next scheduled price, and a small form that appends a new price (never overwrites). */
function PriceRow({ row, busy, onSave, onShowHistory }) {
    const [amount, setAmount] = useState(row.current?.amount ?? '');
    const [startsAt, setStartsAt] = useState('');
    const [reason, setReason] = useState('');
    const [open, setOpen] = useState(false);
    const [error, setError] = useState(null);
    const normalized = normalizeMoney(amount);
    const differs = priceDiffers(row.current, normalized);
    const id = rowKey(row).replaceAll(':', '_');

    async function submit(event) {
        event.preventDefault();
        if (normalized === null) {
            setError('Enter an amount in pesos, for example 68 or 68.50.');
            return;
        }
        setError(null);
        const result = await onSave(row, { amount: normalized, effective_from: effectiveFromPayload(startsAt), reason: reason.trim() || undefined });
        if (result.error) {
            setError(result.error);
        } else {
            setOpen(false);
            setStartsAt('');
            setReason('');
        }
    }

    return (
        <li className="border-t border-slate-800/70 px-3 py-3 first:border-t-0">
            <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-slate-100">
                        {row.label}
                        {row.kind === 'ADDON' && <span className="ml-2 text-xs text-slate-500">add-on</span>}
                        {!row.active && <span className="ml-2 font-mono text-[10px] text-slate-500">HIDDEN</span>}
                    </p>
                    {row.dishes.length > 0 && <p className="text-xs text-slate-500">{row.dishes.join(', ')}</p>}
                </div>
                <div className="text-right">
                    <p className={`font-mono text-lg ${row.current ? 'text-slate-100' : 'text-amber-300'}`}>{row.current ? formatPeso(row.current.amount) : 'No price'}</p>
                    {row.current && <p className="text-[11px] text-slate-500">since {whenLabel(row.current.effective_from)}</p>}
                </div>
                <div className="flex items-center gap-3 text-xs">
                    <button type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} className={linkButton}>
                        {row.current ? 'Change price' : 'Set price'}
                    </button>
                    <button type="button" onClick={() => onShowHistory(row)} className={linkButton}>
                        History
                    </button>
                </div>
            </div>
            {row.upcoming && (
                <p className="mt-1 text-xs text-sky-300">
                    Next: {formatPeso(row.upcoming.amount)} from {whenLabel(row.upcoming.effective_from)}
                </p>
            )}
            {open && (
                <form onSubmit={submit} className="mt-3 grid gap-3 rounded-md border border-slate-700 bg-slate-950/60 p-3 sm:grid-cols-3" noValidate>
                    <div>
                        <label htmlFor={`${id}_amount`} className="mb-1 block text-xs text-slate-400">
                            New price (₱)
                        </label>
                        <input id={`${id}_amount`} inputMode="decimal" autoFocus value={amount} onChange={(event) => setAmount(event.target.value)} aria-invalid={Boolean(error)} className={`${inputClass(error)} font-mono`} />
                    </div>
                    <div>
                        <label htmlFor={`${id}_starts`} className="mb-1 block text-xs text-slate-400">
                            Starts (leave empty for now)
                        </label>
                        <input id={`${id}_starts`} type="datetime-local" value={startsAt} onChange={(event) => setStartsAt(event.target.value)} className={inputClass(false)} />
                    </div>
                    <div>
                        <label htmlFor={`${id}_reason`} className="mb-1 block text-xs text-slate-400">
                            Reason (optional)
                        </label>
                        <input id={`${id}_reason`} type="text" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} className={inputClass(false)} />
                    </div>
                    <div className="sm:col-span-3">
                        <FieldError message={error} />
                        <p className="mb-2 text-xs text-slate-500">Saving adds a new price to the history; earlier prices and past sales are not changed.</p>
                        <div className="flex gap-2">
                            <button type="submit" disabled={busy || !differs} className={primaryButton}>
                                {busy ? 'Saving…' : 'Save price'}
                            </button>
                            <button type="button" onClick={() => setOpen(false)} className={secondaryButton}>
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            )}
        </li>
    );
}

/**
 * /admin/menu/prices/:mealId -- the price grid of a meal by dish category (and its add-ons), with the full price history. A price
 * edit is a new row in the history, effective now or from a chosen later time (ADR-016; invariants.md MENU-004).
 */
export default function MealPricesPage() {
    const { mealId } = useParams();
    const navigate = useNavigate();
    const { setUser } = useAuth();
    const [meals, setMeals] = useState(null);
    const [grid, setGrid] = useState(null);
    const [history, setHistory] = useState(null);
    const [historyPage, setHistoryPage] = useState(1);
    const [historyRow, setHistoryRow] = useState(null);
    const [failure, setFailure] = useState(null);
    const [toast, setToast] = useState(null);
    const [busy, setBusy] = useState(false);
    const seq = useRef(0);
    const historySeq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const loadMeals = useCallback(async () => {
        const result = await fetchAll('/api/v1/meals');
        if (result.ok) {
            setMeals(result.rows);
        } else {
            setFailure(result);
        }
        return result;
    }, []);

    useEffect(() => {
        loadMeals().then((result) => {
            if (result.ok && !mealId && result.rows[0]) {
                navigate(`/admin/menu/prices/${result.rows[0].id}`, { replace: true });
            }
        });
    }, [loadMeals, mealId, navigate]);

    const loadGrid = useCallback(async () => {
        if (!mealId) {
            return;
        }
        const current = ++seq.current;
        const result = await request(`/api/v1/meals/${mealId}/prices`);
        if (current !== seq.current) {
            return;
        }
        if (result.ok) {
            setGrid(result.body.rows);
            setFailure(null);
        } else {
            setGrid(null);
            setFailure(result);
        }
    }, [mealId]);

    const loadHistory = useCallback(async () => {
        if (!mealId) {
            return;
        }
        const current = ++historySeq.current;
        const filter = historyQuery(historyRow);
        const result = await request(`/api/v1/meals/${mealId}/prices/history?per_page=10&page=${historyPage}${filter ? `&${filter}` : ''}`);
        if (current === historySeq.current && result.ok) {
            setHistory(result.body);
        }
    }, [mealId, historyPage, historyRow]);

    useEffect(() => {
        setGrid(null);
        setHistory(null);
        setHistoryRow(null);
        setHistoryPage(1);
        loadGrid();
    }, [loadGrid]);

    useEffect(() => {
        loadHistory();
    }, [loadHistory]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    /** Appends a price. Resolves { error } with a message to show on the row, or {} on success. */
    async function savePrice(row, body) {
        setBusy(true);
        const key = row.kind === 'ADDON' ? { meal_option_id: row.meal_option_id } : { dish_category_id: row.dish_category_id };
        const result = await request(`/api/v1/meals/${mealId}/prices`, { method: 'POST', body: { ...key, ...body } });
        setBusy(false);
        if (!result.ok) {
            const message = Object.values(fieldErrors(result))[0];
            if (message) {
                return { error: message };
            }
            setFailure(result);
            return { error: failureMessage(result, 'The price could not be saved.') };
        }
        setToast(result.status === 201 ? 'Price saved' : 'That is already the price');
        await Promise.all([loadGrid(), loadHistory()]);
        return {};
    }

    const meal = meals?.find((candidate) => candidate.id === mealId) ?? null;

    return (
        <AdminLayout title="Meal menu / Meal prices" requiredCapability="CATALOG_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Meal prices</h2>
            <p className="mb-4 max-w-2xl text-sm text-slate-400">
                A meal costs what its dish category says, for example Beef 68 and Chicken/Pork/Fish 60, whichever dish is chosen. Changing a price adds a line to the history and applies from the
                time you choose; earlier prices and past sales never change.
            </p>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The prices could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => { loadMeals(); loadGrid(); }}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}
            {meals === null && !failure && <Loading label="Loading meals" />}

            {meals !== null && meals.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-300">No meals yet. Build a meal on the Meals screen first.</div>
            )}

            {meals !== null && meals.length > 0 && (
                <>
                    <div className="mb-4 flex items-center gap-2">
                        <label htmlFor="price_meal" className="text-sm text-slate-400">
                            Meal
                        </label>
                        <select id="price_meal" value={mealId ?? ''} onChange={(event) => navigate(`/admin/menu/prices/${event.target.value}`)} className={`${inputClass(false)} w-auto max-w-xs`}>
                            {meals.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                    {option.active ? '' : ' (hidden)'}
                                </option>
                            ))}
                        </select>
                    </div>

                    {grid === null && !failure && <Loading label="Loading prices" />}
                    {grid !== null && grid.length === 0 && (
                        <div className="rounded-lg border border-dashed border-slate-800 p-6 text-center text-sm text-slate-300">
                            This meal offers no dishes or add-ons yet, so there is nothing to price. Add them on the Meals screen.
                        </div>
                    )}
                    {grid !== null && grid.length > 0 && (
                        <ul aria-label={`${meal?.name ?? 'Meal'} prices`} className="mb-6 overflow-hidden rounded-lg border border-slate-800">
                            {grid.map((row) => (
                                <PriceRow key={rowKey(row)} row={row} busy={busy} onSave={savePrice} onShowHistory={(target) => { setHistoryRow(target); setHistoryPage(1); }} />
                            ))}
                        </ul>
                    )}

                    <section aria-label="Price history">
                        <div className="mb-2 flex flex-wrap items-center gap-3">
                            <h3 className="text-base font-semibold text-slate-100">Price history{historyRow ? `: ${historyRow.label}` : ''}</h3>
                            {historyRow && (
                                <button type="button" onClick={() => { setHistoryRow(null); setHistoryPage(1); }} className={linkButton}>
                                    Show all
                                </button>
                            )}
                        </div>
                        {history === null ? (
                            <Loading label="Loading history" />
                        ) : history.data.length === 0 ? (
                            <p className="text-sm text-slate-500">No price has been set yet.</p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-slate-800">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-900 text-xs uppercase tracking-wider text-slate-400">
                                        <tr>
                                            <th scope="col" className="px-3 py-2">Starts</th>
                                            <th scope="col" className="px-3 py-2">For</th>
                                            <th scope="col" className="px-3 py-2 text-right">Price</th>
                                            <th scope="col" className="px-3 py-2">Set by</th>
                                            <th scope="col" className="px-3 py-2">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {history.data.map((entry) => (
                                            <tr key={entry.id} className="border-t border-slate-800/70">
                                                <td className="whitespace-nowrap px-3 py-2 text-slate-300">{whenLabel(entry.effective_from)}</td>
                                                <td className="px-3 py-2 text-slate-100">{entry.dish_category_name ?? entry.meal_option_label}</td>
                                                <td className="px-3 py-2 text-right font-mono text-slate-100">{formatPeso(entry.amount)}</td>
                                                <td className="px-3 py-2 text-slate-300">{entry.changed_by_name ?? '—'}</td>
                                                <td className="px-3 py-2 text-slate-400">{entry.reason ?? ''}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <Pager meta={history?.meta} onPage={setHistoryPage} />
                    </section>
                </>
            )}
        </AdminLayout>
    );
}

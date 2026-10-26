import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AdminLayout from './AdminLayout';
import { ErrorAlert, Toast } from './catalog/CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, request } from './catalog/catalogApi';

const inputClass = (error) =>
    `min-h-11 w-24 rounded-md border bg-slate-950 px-3 py-1.5 text-right font-mono text-sm tabular-nums text-slate-100 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

/** A whole number from 0 to 1000, or null. */
function parseThreshold(text) {
    const value = String(text).trim();

    return /^\d{1,4}$/.test(value) && Number(value) <= 1000 ? Number(value) : null;
}

/**
 * /admin/store-setup/stock-rules -- stockRulesGet / stockRulesSet (ADR-017 section 1, slice A). The store's three stock switches: block a sale
 * that has no stock (off by default, so a store that does nothing sees no change), let a manager approve one blocked sale with their code,
 * and the "N left" warning that every product without a level of its own uses. Administrator only (STORE_SETTINGS_MANAGE). Before blocking is
 * turned on the page says how many tracked items have no stock right now: the sale of each is refused until stock is entered. Turning it
 * on or off never changes a stock figure.
 */
export default function StockRulesPage() {
    const { setUser } = useAuth();
    const [rules, setRules] = useState(null);
    const [failure, setFailure] = useState(null);
    const [block, setBlock] = useState(false);
    const [override, setOverride] = useState(true);
    const [threshold, setThreshold] = useState('3');
    const [confirming, setConfirming] = useState(false);
    const [saveError, setSaveError] = useState(null);
    const [thresholdError, setThresholdError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [toast, setToast] = useState(null);
    const dismissToast = useCallback(() => setToast(null), []);

    const show = useCallback((body) => {
        setRules(body);
        setBlock(body.block_sale_without_stock);
        setOverride(body.stock_override_enabled);
        setThreshold(String(body.low_stock_default_threshold));
    }, []);

    const load = useCallback(() => {
        setFailure(null);
        request('/api/v1/stock-rules').then((response) => (response.ok ? show(response.body) : setFailure(response)));
    }, [show]);

    useEffect(load, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const parsed = parseThreshold(threshold);
    const dirty = rules !== null && (block !== rules.block_sale_without_stock || override !== rules.stock_override_enabled || parsed !== rules.low_stock_default_threshold);
    const turningOn = rules !== null && block && !rules.block_sale_without_stock;

    async function save() {
        setSaving(true);
        setSaveError(null);
        const result = await request('/api/v1/stock-rules', {
            method: 'PUT',
            body: { block_sale_without_stock: block, stock_override_enabled: override, low_stock_default_threshold: parsed },
        });
        setSaving(false);
        setConfirming(false);
        if (result.ok) {
            show(result.body);
            setToast(result.body.block_sale_without_stock ? 'Stock rules saved. Sales without stock are now blocked.' : 'Stock rules saved');
            return;
        }
        if (result.status === 401) {
            signIn();
            return;
        }
        setSaveError(fieldErrors(result).low_stock_default_threshold ?? failureMessage(result, 'The stock rules could not be saved.'));
    }

    function submit(event) {
        event.preventDefault();
        if (parsed === null) {
            setThresholdError('Use a whole number from 0 to 1000.');
            return;
        }
        setThresholdError(null);
        if (turningOn) {
            setConfirming(true);
            return;
        }
        save();
    }

    const preflight = rules?.preflight;

    return (
        <AdminLayout title="Stock rules" requiredCapability="STORE_SETTINGS_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4">
                <h2 className="text-xl font-semibold text-slate-100">Stock rules</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Whether the till refuses to sell what is not in stock, and when it warns that the last few are left. Only an administrator can change these.
                </p>
            </div>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The stock rules could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {rules === null && !failure && <div className="h-48 animate-pulse rounded bg-slate-900" aria-busy="true" aria-label="Loading stock rules" />}

            {rules && (
                <form onSubmit={submit} noValidate className="grid max-w-3xl grid-cols-1 gap-5">
                    <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-4" aria-labelledby="stock_block_heading">
                        <h3 id="stock_block_heading" className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            Selling without stock
                        </h3>

                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                checked={block}
                                onChange={(event) => setBlock(event.target.checked)}
                                className="mt-1 h-5 w-5 rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500"
                            />
                            <span>
                                <span className="block text-sm font-semibold text-slate-100">Do not allow a sale when there is not enough stock</span>
                                <span className="mt-0.5 block text-sm text-slate-400">
                                    The till shows &ldquo;No stock&rdquo; and refuses the sale. Nothing is sold or charged. Products you do not track, meals and rice are never counted. Void and refund are never blocked. When this is off, a sale always goes through and stock may go below zero.
                                </span>
                            </span>
                        </label>

                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                checked={override}
                                onChange={(event) => setOverride(event.target.checked)}
                                className="mt-1 h-5 w-5 rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500"
                            />
                            <span>
                                <span className="block text-sm font-semibold text-slate-100">Let a manager approve one blocked sale with their code</span>
                                <span className="mt-0.5 block text-sm text-slate-400">
                                    For when the count is wrong and a customer is waiting. The manager types their own override code on the till; it covers that one sale, which then takes the stock below zero and is recorded. Turn this off and a blocked sale can only be fixed by entering stock. It only matters while the box above is ticked.
                                </span>
                            </span>
                        </label>

                        {preflight && (
                            <div role="status" aria-label="Items without stock" className="rounded-md border border-slate-700 bg-slate-950 p-3 text-sm text-slate-300">
                                <p>
                                    <span className="font-semibold text-slate-100">{preflight.unsellable_count}</span> of {preflight.tracked_count} tracked item
                                    {preflight.tracked_count === 1 ? '' : 's'} {preflight.unsellable_count === 1 ? 'has' : 'have'} no stock right now.
                                    {block ? ' They cannot be sold until stock is entered.' : ' If you turn blocking on, they cannot be sold until stock is entered.'}
                                </p>
                                {preflight.sample.length > 0 && (
                                    <ul aria-label="Items without stock" className="mt-2 max-h-48 space-y-0.5 overflow-y-auto text-xs text-slate-400">
                                        {preflight.sample.map((item) => (
                                            <li key={item.product_id}>
                                                {item.name} <span className="font-mono text-slate-500">{item.sku}</span>
                                            </li>
                                        ))}
                                        {preflight.unsellable_count > preflight.sample.length && <li>and {preflight.unsellable_count - preflight.sample.length} more</li>}
                                    </ul>
                                )}
                                <p className="mt-2 text-xs text-slate-500">
                                    Enter opening stock first under <Link to="/admin/inventory/stock" className="underline">Inventory, Stock</Link>.
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="space-y-3 rounded-lg border border-slate-800 bg-slate-900 p-4" aria-labelledby="stock_low_heading">
                        <h3 id="stock_low_heading" className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            Low-stock warning
                        </h3>
                        <div>
                            <label htmlFor="low_stock_default_threshold" className="mb-1 block text-sm text-slate-200">
                                Warn when this many or fewer are left
                            </label>
                            <input
                                id="low_stock_default_threshold"
                                type="text"
                                inputMode="numeric"
                                value={threshold}
                                aria-invalid={Boolean(thresholdError)}
                                onChange={(event) => {
                                    setThreshold(event.target.value);
                                    setThresholdError(null);
                                }}
                                className={inputClass(thresholdError)}
                            />
                            {thresholdError ? (
                                <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                                    &#9888; {thresholdError}
                                </p>
                            ) : (
                                <p className="mt-1 text-xs text-slate-500">
                                    The till shows &ldquo;Only 3 left&rdquo; at this number or below, and the low-stock list uses it. A product can have its own level in its Reorder level field; leave that blank to use this number.
                                </p>
                            )}
                        </div>
                    </section>

                    {confirming && (
                        <div role="group" aria-label="Confirm blocking" className="space-y-3 rounded-md border border-amber-700/70 bg-amber-950/20 p-4">
                            <p className="text-sm font-semibold text-slate-100">Turn on blocking?</p>
                            <p className="text-sm text-slate-300">
                                {preflight.unsellable_count} item{preflight.unsellable_count === 1 ? '' : 's'} will be unsellable until stock is entered. Nothing changes in your stock figures.
                            </p>
                            <div className="flex gap-3">
                                <button type="button" onClick={save} disabled={saving} className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-40 lg:min-h-0">
                                    {saving ? 'Saving…' : 'Yes, turn on blocking'}
                                </button>
                                <button type="button" onClick={() => setConfirming(false)} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    )}

                    {saveError && (
                        <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                            {saveError}
                        </p>
                    )}

                    <div>
                        <button
                            type="submit"
                            disabled={saving || !dirty || confirming}
                            className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                        >
                            {saving && !confirming ? 'Saving…' : 'Save stock rules'}
                        </button>
                    </div>
                </form>
            )}
        </AdminLayout>
    );
}

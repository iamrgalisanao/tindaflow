import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ErrorAlert } from './admin/catalog/CatalogParts';
import { failureMessage } from './admin/catalog/catalogApi';
import { fieldClass, useLoad, useSignIn } from './admin/records/historyParts';
import { SummaryCards } from './admin/reports/ReportParts';
import { datePreset, formatMoney, sumIntegers } from './admin/reports/formatters';

const PRESETS = [
    { id: 'today', label: 'Today', heading: 'Today so far' },
    { id: 'yesterday', label: 'Yesterday', heading: 'Yesterday' },
    { id: 'this_week', label: 'This week', heading: 'This week so far' },
    { id: 'this_month', label: 'This month', heading: 'This month so far' },
];

const chip = 'min-h-11 rounded border px-3 text-xs font-medium lg:min-h-8';

/** The dashboard's sales figures, for a preset or for dates of the reader's choosing. */
export default function DashboardSales() {
    // A preset is kept by name and resolved on each render, so "Today" is still today after midnight.
    const [chosen, setChosen] = useState({ preset: 'today' });
    const [draft, setDraft] = useState(() => datePreset('today'));

    const range = chosen.preset ? datePreset(chosen.preset) : chosen;
    const heading = PRESETS.find((preset) => preset.id === chosen.preset)?.heading
        ?? (range.from === range.to ? range.from : `${range.from} to ${range.to}`);
    const incomplete = !draft.from || !draft.to;
    const backwards = !incomplete && draft.from > draft.to;

    function choosePreset(id) {
        setChosen({ preset: id });
        setDraft(datePreset(id));
    }

    function showDates(event) {
        event.preventDefault();
        if (!incomplete && !backwards) {
            setChosen({ preset: null, from: draft.from, to: draft.to });
        }
    }

    return (
        <section className="mb-2" aria-label="Sales">
            <div className="mb-2 flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-wrap gap-1" role="group" aria-label="Sales period">
                    {PRESETS.map((preset) => (
                        <button
                            key={preset.id}
                            type="button"
                            aria-pressed={chosen.preset === preset.id}
                            onClick={() => choosePreset(preset.id)}
                            className={`${chip} ${chosen.preset === preset.id ? 'border-emerald-500 bg-emerald-950/60 text-emerald-300' : 'border-slate-700 text-slate-400 hover:bg-slate-800'}`}
                        >
                            {preset.label}
                        </button>
                    ))}
                </div>
                <form onSubmit={showDates} className="flex flex-wrap items-end gap-2" aria-label="Choose dates">
                    {['from', 'to'].map((end) => (
                        <label key={end} className="text-[11px] uppercase tracking-wide text-slate-500">
                            {end === 'from' ? 'From' : 'To'}
                            <input
                                type="date"
                                value={draft[end]}
                                aria-invalid={backwards}
                                onChange={(event) => setDraft((prior) => ({ ...prior, [end]: event.target.value }))}
                                className={`${fieldClass} block`}
                            />
                        </label>
                    ))}
                    <button type="submit" disabled={incomplete || backwards} className={`${chip} border-slate-600 text-slate-200 hover:bg-slate-800 disabled:opacity-40`}>
                        Show these dates
                    </button>
                </form>
            </div>
            {backwards && <p role="alert" className="mb-2 text-xs text-rose-400">The “From” date is after the “To” date.</p>}

            {/* Keyed by the dates, so figures for one period are never shown under another's heading while loading. */}
            <Figures key={`${range.from}_${range.to}`} range={range} heading={heading} isToday={chosen.preset === 'today'} />
        </section>
    );
}

function Figures({ range, heading, isToday }) {
    const signIn = useSignIn();
    const query = `from=${range.from}&to=${range.to}`;
    const sales = useLoad(`/api/v1/reports/daily-sales-summary?${query}`);

    // The report has one row per business day with sales, and none for a day without: no rows is a real zero, not a failure.
    const summary = sales.data?.summary ?? null;
    const rows = sales.data?.rows ?? [];

    return (
        <>
            <div className="mb-2 flex items-baseline justify-between gap-3">
                <h3 className="font-mono text-[11px] uppercase tracking-wider text-slate-500">{heading}</h3>
                <Link to={`/admin/reports/daily-sales-summary?${query}`} className="text-xs text-emerald-400 hover:underline">
                    Daily sales summary →
                </Link>
            </div>

            {sales.failure && (
                <ErrorAlert
                    message={failureMessage(sales.failure, 'The sales figures could not be loaded.')}
                    onRetry={sales.failure.status === 401 || sales.failure.status === 403 ? undefined : sales.reload}
                    onSignIn={sales.failure.status === 401 ? signIn : undefined}
                />
            )}

            {sales.loading && !sales.data && !sales.failure && (
                <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy="true" aria-label="Loading the sales figures">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-[74px] animate-pulse rounded-lg bg-slate-900" />
                    ))}
                </div>
            )}

            {sales.data && (
                <SummaryCards
                    items={[
                        { label: 'Transactions', node: sumIntegers(rows, 'transaction_count').toLocaleString('en-US') },
                        { label: 'Gross sales', node: formatMoney(summary?.gross_sales ?? '0.00') },
                        { label: 'Discounts', node: formatMoney(summary?.discount_total ?? '0.00') },
                        { label: 'Grand total', node: formatMoney(summary?.grand_total ?? '0.00'), tone: 'emerald' },
                    ]}
                />
            )}

            {sales.data && rows.length === 0 && (
                <p className="mb-3 text-xs text-slate-500">
                    {isToday ? 'No sales have been finalised today yet.' : 'There are no sales for these dates.'} Voided sales are never counted here.
                </p>
            )}
        </>
    );
}

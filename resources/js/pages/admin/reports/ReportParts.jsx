import { Link } from 'react-router-dom';
import ReportsAccessDenied from './ReportsAccessDenied';
import { formatMoney, formatQuantity, formatReportCell, stockTier } from './formatters';

export function Cell({ value, format, row }) {
    if (format === 'stock_bar') {
        return <StockBar row={row} />;
    }
    const { text, className, title } = formatReportCell(value, format);
    return (
        <span className={className} title={title}>
            {text}
        </span>
    );
}

export function SummaryValue({ value, format }) {
    if (value === null || value === undefined) {
        return <span className="text-slate-600">{'—'}</span>;
    }
    if (format === 'currency') {
        return <span className="font-mono tabular-nums">{formatMoney(value)}</span>;
    }
    if (format === 'variance') {
        return <Cell value={value} format="variance" />;
    }
    return <span className="font-mono tabular-nums">{Number(value).toLocaleString('en-US')}</span>;
}

/** On-hand against the reorder level: the bar is the reorder level, the fill is what is on hand. */
export function StockBar({ row }) {
    const onHand = Number(row.quantity_on_hand);
    const reorder = Number(row.reorder_level);
    const percent = reorder > 0 ? Math.max(0, Math.min(100, (onHand / reorder) * 100)) : 0;
    const tier = stockTier(row.quantity_on_hand, row.reorder_level);
    return (
        <span
            role="img"
            aria-label={`${formatQuantity(row.quantity_on_hand)} on hand of a ${formatQuantity(row.reorder_level)} reorder level`}
            className="relative block h-1.5 w-36 rounded-sm bg-slate-800"
        >
            <span
                className={`absolute inset-y-0 left-0 rounded-sm ${tier === 'LOW' ? 'bg-amber-400' : 'bg-rose-400'}`}
                style={{ width: `${percent}%` }}
            />
            <span aria-hidden="true" className="absolute -right-px -top-1 h-3.5 w-0.5 bg-slate-300" />
        </span>
    );
}

const CARD_TONE = {
    emerald: 'border-t-emerald-500',
    rose: 'border-t-rose-500 bg-rose-950/30',
    roseSoft: 'border-t-rose-700 bg-rose-950/15',
    amber: 'border-t-amber-500 bg-amber-950/20',
};

/** items: [{ label, node, sub?, tone? }] */
export function SummaryCards({ items }) {
    return (
        <div className="mb-3 grid grid-cols-2 gap-3 max-lg:[&>*:last-child:nth-child(odd)]:col-span-2 lg:grid-cols-4">
            {items.map((item) => (
                <div
                    key={item.label}
                    className={`rounded-lg border border-t-2 border-slate-800 bg-slate-900 px-4 py-3 ${CARD_TONE[item.tone] ?? 'border-t-slate-700'}`}
                >
                    <p className="text-[11px] uppercase tracking-wide text-slate-500">{item.label}</p>
                    <p className="mt-1 text-lg font-semibold text-slate-100">{item.node}</p>
                    {item.sub && <p className="text-[11px] text-slate-500">({item.sub})</p>}
                </div>
            ))}
        </div>
    );
}

const CHIP_TONE = {
    slate: 'border-slate-600 text-slate-300',
    amber: 'border-amber-700/70 text-amber-400',
    rose: 'border-rose-700/70 text-rose-400',
    emerald: 'border-emerald-700/70 text-emerald-400',
};
const CHIP_ACTIVE = {
    slate: 'bg-slate-800 ring-1 ring-slate-500',
    amber: 'bg-amber-950/60 ring-1 ring-amber-500',
    rose: 'bg-rose-950/60 ring-1 ring-rose-500',
    emerald: 'bg-emerald-950/60 ring-1 ring-emerald-500',
};

/** Client-side status filter over the loaded rows; counts always describe the whole loaded result. */
export function StatusChips({ config, counts, total, value, onChange }) {
    const chips = [{ id: 'all', label: 'All', tone: 'emerald', count: total }].concat(
        config.statuses.map((status) => ({ ...status, count: counts[status.id] ?? 0 })),
    );
    return (
        <div className="mb-3 flex flex-wrap items-center gap-2">
            {chips.map((chip) => (
                <button
                    key={chip.id}
                    type="button"
                    aria-pressed={value === chip.id}
                    onClick={() => onChange(chip.id)}
                    className={`min-h-11 rounded border px-2.5 py-1 font-mono text-xs lg:min-h-0 ${CHIP_TONE[chip.tone]} ${
                        value === chip.id ? CHIP_ACTIVE[chip.tone] : 'hover:bg-slate-800'
                    }`}
                >
                    {chip.label} {chip.count}
                </button>
            ))}
            <span className="w-full text-xs text-slate-500 lg:ml-auto lg:w-auto">{config.note}</span>
        </div>
    );
}

const LEGEND_TONE = {
    REQUESTED: 'text-slate-300',
    APPROVED: 'text-amber-400',
    REJECTED: 'text-rose-400',
    VOIDED: 'text-emerald-400',
    COMPLETED: 'text-emerald-400',
};

export function StatusLegend({ legend }) {
    return (
        <section className="mt-4 rounded-lg border border-slate-800 bg-slate-900 p-4" aria-label={legend.title}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-200">{legend.title}</h3>
                <p className="font-mono text-[11px] text-slate-400">
                    {legend.flow.join(' → ')} <span className="text-slate-600">or</span> {legend.alternative}
                </p>
            </div>
            <dl className="mt-3 grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                {legend.items.map(([status, text]) => (
                    <div key={status}>
                        <dt className={`inline font-mono font-semibold ${LEGEND_TONE[status]}`}>{status}: </dt>
                        <dd className="inline text-slate-400">{text}</dd>
                    </div>
                ))}
            </dl>
            {legend.note && <p className="mt-3 text-xs text-slate-500">Note: {legend.note}</p>}
        </section>
    );
}

function Icon({ children }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width="22"
            height="22"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

const ERROR_KINDS = {
    network: {
        title: 'The report could not be loaded',
        body: 'Check your connection and try again.',
        panel: 'border-dashed border-slate-600',
        icon: (
            <Icon>
                <path d="M2 2l20 20" />
                <path d="M8.5 16.4a5 5 0 0 1 7 0" />
                <path d="M5 12.9a10 10 0 0 1 5.2-2.8" />
                <path d="M13.8 10.1a10 10 0 0 1 5.2 2.8" />
                <path d="M2 8.8a15 15 0 0 1 4.2-2.6" />
                <path d="M22 8.8a15 15 0 0 0-8.6-3.7" />
            </Icon>
        ),
    },
    server: {
        title: 'The server hit a problem building this report.',
        body: 'Try again in a moment. If it keeps happening, tell your administrator.',
        panel: 'border-amber-700/60',
        icon: (
            <Icon>
                <rect x="3" y="4" width="18" height="6" rx="1" />
                <rect x="3" y="14" width="18" height="6" rx="1" />
                <path d="M7 7h.01M7 17h.01" />
            </Icon>
        ),
    },
    session: {
        title: 'Your session has expired. Sign in again to continue.',
        body: 'You will return to this report with your filters kept.',
        panel: 'border-slate-700',
        icon: (
            <Icon>
                <rect x="5" y="11" width="14" height="9" rx="1" />
                <path d="M8 11V8a4 4 0 0 1 8 0v3" />
            </Icon>
        ),
    },
    other: {
        title: 'The report could not be loaded',
        body: 'The server rejected this request.',
        panel: 'border-rose-800',
        icon: (
            <Icon>
                <circle cx="12" cy="12" r="9" />
                <path d="M12 8v5M12 16h.01" />
            </Icon>
        ),
    },
};

/**
 * Replaces the summary cards and table for a failed load. The filter bar stays usable so the user
 * can correct filters and retry; stale rows are never shown next to an error.
 */
export function ErrorPanel({ failure, onRetry, onSignIn }) {
    if (failure.kind === 'forbidden') {
        return <ReportsAccessDenied />;
    }
    const kind = ERROR_KINDS[failure.kind] ?? ERROR_KINDS.other;
    const body = failure.kind === 'other' && failure.message ? failure.message : kind.body;
    return (
        <div
            role="alert"
            className={`flex flex-col gap-4 rounded-lg border bg-slate-900 p-5 sm:flex-row sm:items-center ${kind.panel}`}
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded border border-slate-700 bg-slate-800 text-slate-300">
                {kind.icon}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-100">{kind.title}</p>
                <p className="mt-0.5 text-sm text-slate-400">{body}</p>
                {failure.requestId && (
                    <p className="mt-2 font-mono text-[11px] text-slate-500">Reference: {failure.requestId}</p>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-3">
                {failure.kind !== 'session' && (
                    <Link to="/admin/reports" className="text-sm text-slate-400 underline hover:text-slate-200">
                        Back to all reports
                    </Link>
                )}
                {failure.kind === 'session' ? (
                    <button
                        type="button"
                        onClick={onSignIn}
                        className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                    >
                        Sign in
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={onRetry}
                        className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                    >
                        Retry
                    </button>
                )}
            </div>
        </div>
    );
}

const EXPORT_MESSAGES = {
    network: 'The CSV export could not reach the server. Check your connection.',
    server: 'The CSV export failed (server error).',
    session: 'Your session has expired. Sign in again to export.',
    forbidden: 'You no longer have permission to export this report.',
    other: 'The CSV export failed.',
};

export const exportFailureMessage = (kind) => `${EXPORT_MESSAGES[kind] ?? EXPORT_MESSAGES.other} Your on-screen report is unaffected.`;

/** Export failure: attached to the header and visually separate from a report load error. */
export function ExportFailureBanner({ message, onRetry, onDismiss }) {
    return (
        <div
            role="alert"
            className="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100"
        >
            <span className="min-w-0 flex-1">{message}</span>
            <button
                type="button"
                onClick={onRetry}
                className="min-h-11 rounded border border-rose-700 px-3 py-1 text-xs font-medium text-rose-300 hover:bg-rose-900/40 lg:min-h-0"
            >
                Try again
            </button>
            <button
                type="button"
                onClick={onDismiss}
                aria-label="Dismiss export error"
                className="flex h-11 w-11 items-center justify-center rounded text-slate-400 hover:bg-slate-800 lg:h-7 lg:w-7"
            >
                &times;
            </button>
        </div>
    );
}

export function ExportToast({ filename, onDismiss }) {
    return (
        <div
            role="status"
            className="fixed right-4 top-4 z-50 flex max-w-[calc(100vw-2rem)] items-center gap-3 rounded-md border border-emerald-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
        >
            <span aria-hidden="true" className="text-emerald-400">&#10003;</span>
            <span className="min-w-0 break-words">
                Downloaded <span className="font-mono text-emerald-400">{filename}</span>
            </span>
            <button type="button" onClick={onDismiss} aria-label="Dismiss" className="text-slate-400 hover:text-slate-200">
                &times;
            </button>
        </div>
    );
}

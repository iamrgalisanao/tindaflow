import { Link } from 'react-router-dom';

const TABS = [
    { key: 'register', label: 'Register' },
    { key: 'payment', label: 'Payment' },
    { key: 'shift', label: 'Shift' },
];

function shiftSince(openedAt) {
    if (!openedAt) {
        return null;
    }
    const opened = new Date(openedAt);
    return Number.isNaN(opened.getTime()) ? null : opened.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

/**
 * What a cashier needs to know at a glance: which terminal, who is selling, and since when the shift is open, plus the
 * three places the till has (Register, Payment, Shift) and the two ways out. Payment is not a place to jump to; it is
 * where the Charge button leads, and it is highlighted while a sale is being paid for.
 */
export default function PosHeader({ terminalCode, operatorName, shift, showTabs, view, paying, onView, onLeave }) {
    const since = shiftSince(shift?.opened_at);
    const active = paying ? 'payment' : view;

    /**
     * When the till has something to lose, the parent decides instead of the router: the click is
     * cancelled and the destination handed over, so the cart survives until the cashier confirms.
     */
    function leave(event, to) {
        if (onLeave) {
            event.preventDefault();
            onLeave(to);
        }
    }

    return (
        <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-slate-800 bg-slate-900 px-4 py-2">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <h1 className="text-base font-semibold text-slate-100">POS</h1>
                {terminalCode && (
                    <span className="rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-[11px] font-bold uppercase tracking-wider text-emerald-400">
                        {terminalCode}
                    </span>
                )}
                <span className="text-xs text-slate-400">
                    {operatorName}
                    {since && <span className="font-mono"> &middot; shift open since {since}</span>}
                </span>
            </div>

            {showTabs && (
                <nav aria-label="Till" className="flex gap-1 rounded-lg border border-slate-700 bg-slate-950 p-1">
                    {TABS.map((tab) => {
                        const selected = active === tab.key;
                        const unavailable = tab.key === 'payment' && !paying;
                        return (
                            <button
                                key={tab.key}
                                type="button"
                                disabled={unavailable || paying}
                                aria-current={selected ? 'page' : undefined}
                                onClick={() => onView(tab.key)}
                                className={`min-h-11 rounded px-4 text-xs font-bold uppercase tracking-wider disabled:cursor-default ${
                                    selected ? 'bg-emerald-500 text-slate-950' : 'text-slate-400 hover:bg-slate-800 disabled:text-slate-600 disabled:hover:bg-transparent'
                                }`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </nav>
            )}

            <div className="flex items-center gap-1 text-sm">
                <Link to="/admin/sales" onClick={(event) => leave(event, '/admin/sales')} className="flex min-h-11 items-center px-3 text-slate-400 underline">
                    Sales history
                </Link>
                <Link to="/" onClick={(event) => leave(event, '/')} className="flex min-h-11 items-center px-3 text-slate-400 underline">
                    Dashboard
                </Link>
            </div>
        </header>
    );
}

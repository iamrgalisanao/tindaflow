import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { request } from '../catalog/catalogApi';
import { fromThousandths, quantityText, toThousandths } from './inventoryParts';

/**
 * Whether this browser is an enrolled terminal. Writes that touch the stock ledger (posting a count, moving
 * stock) are attributed to a terminal, so they need one; drafting a count does not. 'unknown' (the check itself
 * failed) is treated as "try it and let the server say", exactly as the Stock page does.
 */
export function useTerminalState() {
    const [terminal, setTerminal] = useState({ state: 'checking' }); // checking | enrolled | not-enrolled | unknown

    useEffect(() => {
        request('/api/v1/terminal/current').then((response) => {
            if (response.ok) {
                setTerminal({ state: 'enrolled', code: response.body.terminal_code });
            } else if (response.status === 403 && response.body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
                setTerminal({ state: 'not-enrolled' });
            } else {
                setTerminal({ state: 'unknown' });
            }
        });
    }, []);

    return terminal;
}

export function TerminalNotice({ terminal, canManageTerminals, doing }) {
    if (terminal.state !== 'not-enrolled') {
        return null;
    }
    return (
        <div role="status" className="mb-3 rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-200">
            This browser is not enrolled as a terminal, so it cannot {doing}.{' '}
            {canManageTerminals ? (
                <Link to="/admin/terminals" className="text-emerald-400 underline">
                    Enroll this browser
                </Link>
            ) : (
                'Ask an administrator to enroll it.'
            )}
        </div>
    );
}

const STATUS_TONE = {
    OPEN: 'border-sky-700/70 bg-sky-950/40 text-sky-300',
    POSTED: 'border-emerald-700/70 bg-emerald-950/70 text-emerald-300',
    CANCELLED: 'border-slate-700 bg-slate-800/60 text-slate-400',
};

const STATUS_LABEL = { OPEN: 'IN PROGRESS', POSTED: 'POSTED', CANCELLED: 'CANCELLED' };

export function CountStatusBadge({ status }) {
    return (
        <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${STATUS_TONE[status] ?? STATUS_TONE.CANCELLED}`}>
            {STATUS_LABEL[status] ?? status}
        </span>
    );
}

/** A signed difference: more than expected is green, less is amber, none is muted. Sign and colour, never colour alone. */
export function Variance({ value }) {
    const thousandths = toThousandths(value);
    const tone = thousandths > 0 ? 'text-emerald-400' : thousandths < 0 ? 'text-amber-400' : 'text-slate-500';
    return <span className={`font-mono tabular-nums ${tone}`}>{thousandths === 0 ? '0' : quantityText(value, true)}</span>;
}

/** A quantity as plain text for an input: no thousands separators, no insignificant zeros. */
export function plainQuantity(value) {
    const text = fromThousandths(toThousandths(value));
    return text.replace(/\.?0+$/, '') || '0';
}

import { useEffect, useState } from 'react';
import { failureMessage, request } from '../catalog/catalogApi';

const STATUS_TONE = {
    COMPLETED: 'border-emerald-700/70 text-emerald-300',
    VOIDED: 'border-rose-800 text-rose-400',
    PARTIALLY_REFUNDED: 'border-amber-700/70 text-amber-400',
    REFUNDED: 'border-amber-700/70 text-amber-400',
    REQUESTED: 'border-sky-700/70 text-sky-300',
    APPROVED: 'border-sky-700/70 text-sky-300',
    REJECTED: 'border-slate-600 text-slate-400',
};

export function StatusBadge({ status }) {
    return (
        <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${STATUS_TONE[status] ?? STATUS_TONE.REJECTED}`}>
            {status.replaceAll('_', ' ')}
        </span>
    );
}

export const PAYMENT_METHODS = ['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'];

export const DISPOSITIONS = [
    { id: 'RETURN_TO_STOCK', label: 'Return to stock', hint: 'Goes back on the shelf and is counted again.' },
    { id: 'DAMAGED', label: 'Damaged', hint: 'Not sellable. Stock is not added back.' },
    { id: 'EXPIRED', label: 'Expired', hint: 'Not sellable. Stock is not added back.' },
    { id: 'DISPOSED', label: 'Disposed', hint: 'Thrown away. Stock is not added back.' },
];

/**
 * The enrolled-terminal state, for screens that record a void or refund (they are attributed to this
 * terminal). checking | enrolled | not-enrolled | unknown -- `unknown` (the check itself failed) lets the
 * action through and leaves the server to decide.
 */
export function useTerminalState() {
    const [state, setState] = useState('checking');

    useEffect(() => {
        let cancelled = false;
        request('/api/v1/terminal/current').then((response) => {
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setState('enrolled');
            } else if (response.status === 403 && response.body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
                setState('not-enrolled');
            } else {
                setState('unknown');
            }
        });
        return () => {
            cancelled = true;
        };
    }, []);

    return state;
}

/**
 * A plain-language message for a failed void/refund call. The API's own message is used for the
 * business rules (it already says what is wrong); the codes below need a next step the API cannot know.
 */
export function reversalMessage(result, whatFailed) {
    const code = result.body?.error?.code;
    switch (code) {
        case 'TERMINAL_NOT_ENROLLED':
            return 'This browser is not enrolled as a terminal, so it cannot process this. An administrator can enroll it under Terminals.';
        case 'SHIFT_NOT_OPEN':
            return 'You need an open shift at this terminal to do this. Open one from the POS screen, then try again.';
        case 'FISCAL_DAY_CLOSED':
            return 'This terminal has no open fiscal day, so this cannot be processed here. Open the day from the POS screen, then try again.';
        case 'IDEMPOTENCY_KEY_REUSED':
            return 'That request was already used for something different. Review it and submit again.';
        default:
            return failureMessage(result, whatFailed);
    }
}

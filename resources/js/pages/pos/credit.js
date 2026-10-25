import { moneyText, pesos, toCents } from './posMoney';

/**
 * The till's side of employee credit (ADR-016 slice 3.2). Everything here is a PREVIEW: the server decides the limit, under a lock, when
 * the sale is finalised. These helpers only turn the lookup / credit-status answer into whole centavos (BigInt, never floating point) and
 * into the words the cashier reads.
 */

/** The employee a credit sale is charged to, as the register holds it. `credit` is the status for the current cut-off. */
export function payerFromLookup(body) {
    return {
        id: body.id,
        code: body.employee_code,
        type: body.employee_type,
        name: body.name,
        credit: {
            limit: body.credit.limit,
            used: body.credit.used,
            remaining: body.credit.remaining,
            periodStart: body.credit.period_start,
            periodEnd: body.credit.period_end,
        },
    };
}

/** Centavos left in the current cut-off; may be negative if a limit was lowered below what is used. Null when unknown. */
export function remainingCents(credit) {
    if (!credit) {
        return null;
    }
    const text = String(credit.remaining ?? '');
    const negative = text.startsWith('-');
    const cents = toCents(negative ? text.slice(1) : text);

    return cents === null ? null : negative ? -cents : cents;
}

/** The balance left AFTER this cart, in centavos (negative = the cart is over the limit). */
export function remainingAfter(credit, totalCents) {
    const remaining = remainingCents(credit);

    return remaining === null ? null : remaining - totalCents;
}

export const isOverLimit = (credit, totalCents) => {
    const after = remainingAfter(credit, totalCents);

    return after !== null && after < 0n;
};

/** "TSC" or "SUB": how staff say it. */
export const typeLabel = (type) => (type === 'SUBCONTRACTOR' ? 'SUB' : 'TSC');

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "Oct 16 - Oct 31" from two YYYY-MM-DD dates (no time zone maths: they are the store's calendar dates already). */
export function periodText(start, end) {
    const part = (date) => {
        const [, month, day] = String(date).split('-');

        return `${MONTHS[Number(month) - 1] ?? month} ${Number(day)}`;
    };

    return `${part(start)} – ${part(end)}`;
}

/** The request body parts of a credit sale: one CREDIT payment for the whole total, and the employee. */
export function creditSaleParts(employeeId, totalCents) {
    return { payments: [{ method: 'CREDIT', amount: moneyText(totalCents) }], employee_id: employeeId };
}

/** Why a lookup found nobody, in words for the cashier. `result` is the {ok, status, body} of GET /employees/lookup. */
export function lookupFailureText(result) {
    const code = result.body?.error?.code;
    if (result.status === 404 || code === 'EMPLOYEE_NOT_FOUND') {
        return 'No employee has this code. Scan the card again or check the number.';
    }
    if (code === 'EMPLOYEE_INACTIVE') {
        return 'This employee is inactive and cannot be charged. Ask an administrator.';
    }
    if (result.status === 429) {
        return 'Too many lookups in a minute. Wait a moment, then scan again.';
    }
    if (result.status === 401) {
        return 'Your session expired. Sign in again, then scan the card again.';
    }
    if (result.status === 422) {
        return 'Scan the employee card or type the code.';
    }

    return result.status === 0 || result.status === 502 ? 'The connection dropped. Try again.' : (result.body?.error?.message ?? 'The employee could not be looked up.');
}

/**
 * The figures behind a refused credit sale (CREDIT_LIMIT_EXCEEDED details), or null when the failure was something else. There is no
 * override at the till yet: the cashier removes items or takes cash.
 */
export function limitRefusal(result) {
    const error = result?.body?.error;
    if (error?.code !== 'CREDIT_LIMIT_EXCEEDED') {
        return null;
    }
    const d = error.details ?? {};

    return {
        limit: d.limit, used: d.used, remaining: d.remaining, requested: d.requested,
        periodStart: d.period_start, periodEnd: d.period_end, employeeCode: d.employee_code,
    };
}

export const money = (text) => {
    const cents = toCents(String(text ?? '').replace(/^-/, ''));

    return cents === null ? String(text ?? '') : `${String(text).startsWith('-') ? '-' : ''}₱${pesos(cents)}`;
};

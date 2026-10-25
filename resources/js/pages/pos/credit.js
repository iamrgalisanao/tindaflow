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
 * The figures behind a refused credit sale (CREDIT_LIMIT_EXCEEDED details), or null when the failure was something else. The cashier
 * removes items, takes cash, or asks a manager to approve this one sale (ADR-016 section 6, slice 3.3).
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

// ---------------------------------------------------------------------------------------------------------------------------------
// Supervisor override (ADR-016 section 6, slice 3.3). The code a manager types is held in the approval panel's own state for the moment it
// takes to send it, then cleared; it is never stored, logged or put in a URL. The token that comes back lives in memory for ONE sale.

export const OVERRIDE_CODE_MIN = 6;
export const OVERRIDE_CODE_MAX = 8;

/** Digits only, at most eight: what a typed or pasted code is cleaned to. */
export const cleanOverrideCode = (text) => String(text ?? '').replace(/\D/g, '').slice(0, OVERRIDE_CODE_MAX);

/** The held approval for one sale, from creditOverrideGrant's answer. */
export function grantFromResponse(body) {
    return { token: body.token, grantId: body.grant_id, maxAmount: body.max_amount, expiresAt: body.expires_at, approverName: body.approver?.name ?? '' };
}

/** True for any of the four CREDIT_OVERRIDE_* refusals: the held approval is then no use and is dropped. */
export const isOverrideRefusal = (result) => String(result?.body?.error?.code ?? '').startsWith('CREDIT_OVERRIDE_');

/**
 * What the cashier reads after the approval request failed. Deliberately says nothing about WHY a code failed beyond "did not work", and
 * nothing about how many tries are left.
 */
export function overrideFailureText(result) {
    const code = result?.body?.error?.code;
    if (code === 'CREDIT_OVERRIDE_INVALID') {
        return 'That code did not work.';
    }
    if (code === 'CREDIT_OVERRIDE_LOCKED') {
        return result.body.error.message ?? 'Too many wrong codes. This manager is locked for a while. Ask another manager, or wait.';
    }
    if (code === 'CREDIT_OVERRIDE_NOT_ALLOWED' || code === 'CREDIT_OVERRIDE_EXPIRED') {
        return result.body.error.message;
    }
    if (result?.status === 429) {
        return 'Too many tries from this till. Wait a few minutes, then try again.';
    }
    if (result?.status === 401) {
        return 'Your session expired. Sign in again, then ask for the approval again.';
    }
    if (result?.status === 0 || result?.status === 502) {
        return 'The connection dropped. Nothing was approved. Try again.';
    }

    return result?.body?.error?.message ?? 'The approval could not be checked. Try again.';
}

import { normalizeMoney } from '../catalog/catalogApi';
import { formatMoney } from '../reports/formatters';
import { manilaToday } from './billingConfig';

/**
 * Pure helpers for the "Cheques and balance" screen (ADR-016 section 8, slice 4.2). Money is a string with two decimals; the sums
 * below work in whole centavos as BigInt, never floating point. The server re-checks everything and has the last word.
 */

export const MAX_DATE_GAP_DAYS = 365;

export const PERIOD_STATUS = {
    OPEN: { label: 'Open', tone: 'amber' },
    PARTLY_PAID: { label: 'Partly paid', tone: 'sky' },
    PAID: { label: 'Paid', tone: 'emerald' },
    OVERPAID: { label: 'Overpaid (credit to TSC)', tone: 'rose' },
    NO_CHARGES: { label: 'No charges', tone: 'slate' },
};

export function periodStatusLabel(status) {
    return PERIOD_STATUS[status]?.label ?? String(status ?? '');
}

/** "12500.5" -> 1250050n; null when it is not a positive-or-zero money amount. */
export function toCents(text) {
    const money = normalizeMoney(text ?? '');
    return money === null ? null : BigInt(money.replace('.', ''));
}

/** 1250050n -> "12500.50" (negative allowed). */
export function fromCents(cents) {
    const negative = cents < 0n;
    const digits = (negative ? -cents : cents).toString().padStart(3, '0');
    return `${negative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** The periods that still owe something, oldest first: what a cheque can pay. */
export function openPeriods(periods) {
    return [...(periods ?? [])]
        .filter((period) => toCents(period.owed_amount) !== null && BigInt(String(period.owed_amount).replace('.', '')) > 0n)
        .sort((a, b) => a.period_start.localeCompare(b.period_start));
}

/**
 * The oldest-first proposal: pay each open period in turn, up to what it owes, until the cheque is used up.
 * Returns { allocations: { [period_start]: 'amount' }, leftover: 'amount not placed' } -- leftover is "0.00" when the cheque fits.
 */
export function proposeAllocation(periods, amountText) {
    const total = toCents(amountText);
    const allocations = {};
    if (total === null || total <= 0n) {
        return { allocations, leftover: '0.00' };
    }
    let remaining = total;
    for (const period of openPeriods(periods)) {
        if (remaining <= 0n) {
            break;
        }
        const owed = BigInt(String(period.owed_amount).replace('.', ''));
        const take = owed < remaining ? owed : remaining;
        allocations[period.period_start] = fromCents(take);
        remaining -= take;
    }
    return { allocations, leftover: fromCents(remaining) };
}

/**
 * The live check of the allocations the user typed: { sum, difference (cheque - allocated), rowErrors: { start: message }, matches }.
 * `matches` is true only when every typed amount is valid and not more than that period owes and the sum equals the cheque amount.
 */
export function checkAllocations(periods, amountText, typed) {
    const owedByStart = Object.fromEntries((periods ?? []).map((period) => [period.period_start, BigInt(String(period.owed_amount).replace('.', ''))]));
    const rowErrors = {};
    let sum = 0n;
    for (const [start, text] of Object.entries(typed ?? {})) {
        if (String(text).trim() === '') {
            continue;
        }
        const cents = toCents(text);
        if (cents === null || cents <= 0n) {
            rowErrors[start] = 'Enter an amount in pesos.';
            continue;
        }
        sum += cents;
        const owed = owedByStart[start] ?? 0n;
        if (cents > owed) {
            rowErrors[start] = owed > 0n ? `Only ${formatMoney(fromCents(owed))} is owed for this period.` : 'Nothing is owed for this period.';
        }
    }
    const total = toCents(amountText);
    const difference = total === null ? null : total - sum;

    return {
        sum: fromCents(sum),
        difference: difference === null ? null : fromCents(difference),
        rowErrors,
        matches: total !== null && total > 0n && sum > 0n && difference === 0n && Object.keys(rowErrors).length === 0,
    };
}

function daysBetween(from, to) {
    const parse = (value) => {
        const [year, month, day] = value.split('-').map(Number);
        return Date.UTC(year, month - 1, day);
    };
    return Math.round((parse(to) - parse(from)) / 86400000);
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function isRealDate(value) {
    if (!DATE.test(value ?? '')) {
        return false;
    }
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

/** Field checks before sending: { field: message }. */
export function validateCheque(form, now = new Date()) {
    const errors = {};
    const today = manilaToday(now);
    if (!/^[A-Za-z0-9][A-Za-z0-9 \-/]*$/.test(String(form.chequeNumber ?? '').trim())) {
        errors.chequeNumber = 'Enter the cheque number (letters, digits, spaces, dashes).';
    } else if (form.chequeNumber.trim().length > 40) {
        errors.chequeNumber = 'The cheque number is too long.';
    }
    if (String(form.bank ?? '').trim().length < 2) {
        errors.bank = 'Enter the bank.';
    }
    const cents = toCents(form.amount);
    if (cents === null || cents <= 0n) {
        errors.amount = 'Enter the cheque amount in pesos, more than zero.';
    }
    if (!isRealDate(form.receivedOn)) {
        errors.receivedOn = 'Enter the date received.';
    } else if (form.receivedOn > today) {
        errors.receivedOn = 'The date received cannot be in the future.';
    }
    if (!isRealDate(form.chequeDate)) {
        errors.chequeDate = 'Enter the date on the cheque.';
    } else if (!errors.receivedOn && Math.abs(daysBetween(form.receivedOn, form.chequeDate)) > MAX_DATE_GAP_DAYS) {
        errors.chequeDate = 'The cheque date is more than a year from the date received. Check the year.';
    }
    return errors;
}

/** The body of billingChequeRecord: only the periods with an amount, oldest first. */
export function chequePayload(form, typed) {
    const money = normalizeMoney(form.amount);
    return {
        cheque_number: form.chequeNumber.trim(),
        bank: form.bank.trim(),
        cheque_date: form.chequeDate,
        amount: money,
        received_on: form.receivedOn,
        notes: form.notes?.trim() ? form.notes.trim() : null,
        allocations: Object.entries(typed)
            .filter(([, text]) => String(text).trim() !== '')
            .map(([start, text]) => ({ period_start: start, amount: normalizeMoney(text) }))
            .sort((a, b) => a.period_start.localeCompare(b.period_start)),
    };
}

export function emptyChequeForm(now = new Date()) {
    const today = manilaToday(now);
    return { chequeNumber: '', bank: '', chequeDate: today, amount: '', receivedOn: today, notes: '' };
}

/** "Oct 1-15" style summary of what a cheque paid, for the list. */
export function allocationSummary(cheque) {
    return (cheque.allocations ?? []).map((allocation) => `${allocation.period_start} to ${allocation.period_end}: ${formatMoney(allocation.amount)}`);
}

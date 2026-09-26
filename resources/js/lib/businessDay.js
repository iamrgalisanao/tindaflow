/**
 * A fiscal day's `business_date` is the store's local date on which the day was opened. A day is stale when it is still
 * open but dated before today: the shop has not closed (Z-read) it, so today's sales are being recorded under it
 * (invariants.md #9). The frozen state machine deliberately lets a day stay open, because a store may trade past
 * midnight, so this only ever informs; it never blocks a sale.
 *
 * "Today" is the till's own local date, which is the store's date for a till in the shop.
 */
export function localDateString(now = new Date()) {
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

/** @param {string|null|undefined} businessDate YYYY-MM-DD */
export function isStaleBusinessDay(businessDate, now = new Date()) {
    return typeof businessDate === 'string' && businessDate < localDateString(now);
}

/** "2026-09-26" -> "26 Sep 2026", read as a calendar date (no timezone shift). */
export function formatBusinessDate(businessDate) {
    const [year, month, day] = businessDate.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

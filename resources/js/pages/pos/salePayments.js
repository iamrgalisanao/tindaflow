import { REFERENCE_METHODS } from '../../lib/paymentMethods';
import { moneyText, toCents } from './posMoney';

/**
 * The payments a sale is sent with, from the Payment screen's rows. A blank or zero row (shown while a split payment is still
 * being typed) is never sent. A reference number goes only with a method that takes one, and only when something was typed.
 */
export function salePayments(rows) {
    return rows
        .filter((row) => (toCents(row.amount) ?? 0n) > 0n)
        .map((row) => {
            const reference = REFERENCE_METHODS.includes(row.method) ? (row.reference ?? '').trim() : '';

            return { method: row.method, amount: moneyText(toCents(row.amount) ?? 0n), ...(reference ? { external_reference: reference } : {}) };
        });
}

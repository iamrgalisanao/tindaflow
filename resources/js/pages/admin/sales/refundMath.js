/**
 * Client-side mirror of the server's refund arithmetic (domain-model.md SS2.9), used only to preview
 * what a refund will come to and to pre-fill the settlement. The server derives every amount itself and
 * is authoritative: a mismatch comes back as REFUND_SETTLEMENT_MISMATCH. Money is handled as integer
 * cents and quantities as integer thousandths in BigInt, never through floating point.
 */

export function toCents(money) {
    const text = String(money);
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const cents = BigInt(whole || '0') * 100n + BigInt(`${fraction}00`.slice(0, 2));
    return negative ? -cents : cents;
}

export function fromCents(cents) {
    const negative = cents < 0n;
    const absolute = negative ? -cents : cents;
    return `${negative ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}

export function toThousandths(quantity) {
    const [whole, fraction = ''] = String(quantity).split('.');
    return BigInt(whole || '0') * 1000n + BigInt(`${fraction}000`.slice(0, 3));
}

export function fromThousandths(value) {
    const negative = value < 0n;
    const absolute = negative ? -value : value;
    return `${negative ? '-' : ''}${absolute / 1000n}.${String(absolute % 1000n).padStart(3, '0')}`;
}

/** "2.000" -> "2", "0.500" -> "0.5" */
export function trimQuantity(quantity) {
    const [whole, fraction = ''] = String(quantity).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return trimmed ? `${whole}.${trimmed}` : whole;
}

export const QUANTITY_PATTERN = /^\d{1,7}(\.\d{1,3})?$/;

/**
 * The amount one refund event returns for a line: owed after this event (cumulative quantity share of
 * net_line_amount, rounded half up to the cent) minus what earlier completed refunds already paid out.
 *
 * @returns {bigint} cents
 */
export function refundEventCents({ netLineAmount, saleQuantity, priorQuantity, priorAmount, requestedQuantity }) {
    const cumulative = toThousandths(priorQuantity) + toThousandths(requestedQuantity);
    const denominator = toThousandths(saleQuantity);
    const owed = (2n * toCents(netLineAmount) * cumulative + denominator) / (2n * denominator);
    return owed - toCents(priorAmount);
}

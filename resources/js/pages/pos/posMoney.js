/**
 * The arithmetic the till PREVIEWS before a sale is sent: line totals, the cart total, change due. The server recomputes
 * every figure when the sale is finalised and is the only authority; this exists so the cashier sees what they are about
 * to charge. It is done in whole cents (BigInt), never floating point, so 3 x 0.10 is 30 centavos and not 0.30000000000000004.
 *
 * Prices and money are decimal text with up to two places; quantities are decimal text with up to three.
 */

const MONEY = /^\d{1,10}(\.\d{1,2})?$/;
const QUANTITY = /^\d{1,7}(\.\d{1,3})?$/;

/** @returns {bigint|null} centavos, or null when the text is not a money amount */
export function toCents(text) {
    const value = String(text ?? '').trim();
    if (!MONEY.test(value)) {
        return null;
    }
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(`${fraction}00`.slice(0, 2));
}

/** @returns {bigint|null} thousandths of a unit, or null when the text is not a quantity above zero */
export function toThousandths(text) {
    const value = String(text ?? '').trim();
    if (!QUANTITY.test(value)) {
        return null;
    }
    const [whole, fraction = ''] = value.split('.');
    const thousandths = BigInt(whole) * 1000n + BigInt(`${fraction}000`.slice(0, 3));
    return thousandths > 0n ? thousandths : null;
}

export function fromThousandths(thousandths) {
    const whole = thousandths / 1000n;
    const fraction = String(thousandths % 1000n).padStart(3, '0').replace(/0+$/, '');
    return fraction === '' ? String(whole) : `${whole}.${fraction}`;
}

/** Price times quantity, in centavos, rounded half up. Null when the quantity is not valid yet (a half-typed box). */
export function lineCents(price, quantity) {
    const cents = toCents(price);
    const thousandths = toThousandths(quantity);
    if (cents === null || thousandths === null) {
        return null;
    }
    return (cents * thousandths + 500n) / 1000n;
}

/** "1,300.00" from centavos. */
export function pesos(cents) {
    const negative = cents < 0n;
    const absolute = negative ? -cents : cents;
    const whole = (absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${negative ? '-' : ''}${whole}.${String(absolute % 100n).padStart(2, '0')}`;
}

/** The plain two-decimal text the API takes for an amount, from centavos: "1300.00". */
export function moneyText(cents) {
    return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}

/** A discount amount, parsed and clamped to [0, maxCents] -- a blank/invalid box or a negative amount previews as no
 * discount at all; a discount typed larger than what it is discounting from previews as the whole amount instead of
 * a negative total. A display convenience only: the server recomputes and validates the real figure. */
export function clampedDiscountCents(discountText, maxCents) {
    const raw = toCents(discountText) ?? 0n;
    if (raw <= 0n) {
        return 0n;
    }
    return raw > maxCents ? maxCents : raw;
}

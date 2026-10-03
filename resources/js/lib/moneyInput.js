/**
 * The text rules behind <MoneyInput>: a Philippine peso amount typed as the cashier goes, shown as "₱1,000.00" and handed to
 * the rest of the screen as the plain text the API takes ("1000.00"). All of it is text, never a float, so nothing is rounded
 * on the way (see pos/posMoney.js, which reads the same text). At most 10 whole digits and 2 decimals, as the server allows.
 */

const MAX_WHOLE_DIGITS = 10;
const MAX_DECIMALS = 2;

/** What the box holds after a keystroke or a paste: digits and at most one dot, trimmed to the allowed size. May end in "." */
export function parseTyped(raw) {
    const text = String(raw ?? '').replace(/[^\d.]/g, '');
    const dot = text.indexOf('.');
    const wholeRaw = dot === -1 ? text : text.slice(0, dot);
    const fraction = dot === -1 ? null : text.slice(dot + 1).replace(/\./g, '').slice(0, MAX_DECIMALS);

    let whole = wholeRaw.slice(0, MAX_WHOLE_DIGITS).replace(/^0+(?=\d)/, '');
    if (whole === '' && fraction !== null) {
        whole = '0';
    }

    return fraction === null ? whole : `${whole}.${fraction}`;
}

/** The text the rest of the screen sees: as typed, without a dangling dot ("1000." is "1000"). */
export function canonicalMoney(typed) {
    return String(typed ?? '').replace(/\.$/, '');
}

/** Two decimals, once the cashier leaves the box: "1000" is "1000.00", "5.5" is "5.50". An empty box stays empty. */
export function padMoney(typed) {
    const text = canonicalMoney(typed);
    if (text === '') {
        return '';
    }
    const [whole, fraction = ''] = text.split('.');

    return `${whole}.${fraction.padEnd(MAX_DECIMALS, '0')}`;
}

/** "₱1,000.00" (or "1,000.00" with symbol: false) from what was typed. */
export function displayMoney(typed, { symbol = true } = {}) {
    const text = String(typed ?? '');
    if (text === '') {
        return '';
    }
    const dot = text.indexOf('.');
    const whole = (dot === -1 ? text : text.slice(0, dot)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const rest = dot === -1 ? '' : text.slice(dot);

    return `${symbol ? '₱' : ''}${whole}${rest}`;
}

/** How many digits and dots come before a position: the part of a caret position that survives re-formatting. */
export function significantBefore(text, position) {
    return (String(text).slice(0, position).match(/[\d.]/g) ?? []).length;
}

/** The caret position in the formatted text that sits after the given number of digits and dots. */
export function positionAfter(formatted, significant, { symbol = true } = {}) {
    if (significant <= 0) {
        return symbol && formatted.startsWith('₱') ? 1 : 0;
    }
    let seen = 0;
    for (let index = 0; index < formatted.length; index += 1) {
        if (/[\d.]/.test(formatted[index])) {
            seen += 1;
            if (seen === significant) {
                return index + 1;
            }
        }
    }

    return formatted.length;
}

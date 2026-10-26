import { fromThousandths, toThousandths } from './posMoney';

/**
 * The till's side of stock that blocks sales (ADR-017 slice A). Everything here is ADVISORY: it turns GET /api/v1/stock/availability
 * into what the cashier reads on a tile and in the cart, and refuses an add that cannot work, so a hopeless basket is stopped at the
 * counter. The server decides at checkout (INSUFFICIENT_STOCK), on locked rows, and the stock figure here may be up to a minute old.
 * Quantities are whole thousandths (BigInt), never floating point. Prices and costs are not part of this: only quantities.
 */

/** Before the first read, or when it failed: no tile or line is marked and nothing is refused locally. */
export const NO_STOCK_RULES = { loaded: false, blocking: false, overrideAvailable: false, defaultThreshold: 3, byProduct: {} };

/** A signed 3-decimal quantity ("-2.000", "5.000") in thousandths; null when it is not one. */
function signedThousandths(text) {
    const match = /^(-?)(\d{1,10})(?:\.(\d{1,3}))?$/.exec(String(text ?? '').trim());
    if (!match) {
        return null;
    }
    const value = BigInt(match[2]) * 1000n + BigInt(`${match[3] ?? ''}000`.slice(0, 3));

    return match[1] === '-' ? -value : value;
}

/** The availability answer as the register holds it. Anything that is not the expected shape leaves the till without stock marks. */
export function stockFromResponse(body) {
    if (!body || !Array.isArray(body.items)) {
        return NO_STOCK_RULES;
    }
    const byProduct = {};
    for (const item of body.items) {
        const remaining = signedThousandths(item.remaining);
        if (remaining !== null) {
            byProduct[item.product_id] = { remaining, threshold: Number(item.threshold ?? body.low_stock_default_threshold ?? 3) };
        }
    }

    return {
        loaded: true,
        blocking: body.blocking === true,
        overrideAvailable: body.override_available === true,
        defaultThreshold: Number(body.low_stock_default_threshold ?? 3),
        byProduct,
    };
}

/** "5" or "2.5": a quantity without trailing zeros. */
export function quantityLabel(thousandths) {
    const negative = thousandths < 0n;

    return `${negative ? '-' : ''}${fromThousandths(negative ? -thousandths : thousandths)}`;
}

/** What each tracked product demands across the cart. A meal line demands nothing here (its backing product is not tracked). @returns {{[productId: string]: bigint}} */
export function cartDemand(cart) {
    const demand = {};
    for (const line of cart) {
        if (line.meal) {
            continue;
        }
        const quantity = toThousandths(line.quantity);
        if (quantity !== null) {
            demand[line.product.id] = (demand[line.product.id] ?? 0n) + quantity;
        }
    }

    return demand;
}

/**
 * What a product's TILE shows. Null for a product that is not tracked (or before the stock is known).
 * `tone` is 'ok' (a quiet "N in stock"), 'low' (amber "Only N left", at or below the threshold) or 'out' ("No stock"); `blocked` is true
 * only when the store blocks sales without stock and there is none, which is when the tile is not tappable.
 */
export function tileStock(stock, productId) {
    const tracked = stock.byProduct[productId];
    if (!tracked) {
        return null;
    }
    const { remaining, threshold } = tracked;
    if (remaining <= 0n) {
        return { tone: 'out', text: 'No stock', blocked: stock.blocking };
    }
    if (remaining <= BigInt(threshold) * 1000n) {
        return { tone: 'low', text: `Only ${quantityLabel(remaining)} left`, blocked: false };
    }

    return { tone: 'ok', text: `${quantityLabel(remaining)} in stock`, blocked: false };
}

/**
 * What a CART LINE shows, from the whole cart's demand on its product: 'over' when the cart asks for more than is there (red), 'low' when
 * the cart leaves the threshold or less (amber), otherwise 'ok' with the remaining count. Null for an untracked line or a meal.
 */
export function lineStock(stock, cart, line) {
    const tracked = line.meal ? undefined : stock.byProduct[line.product.id];
    if (!tracked) {
        return null;
    }
    const { remaining, threshold } = tracked;
    const demand = cartDemand(cart)[line.product.id] ?? 0n;
    const after = remaining - demand;
    if (after < 0n) {
        return {
            tone: 'over',
            text: remaining <= 0n ? 'No stock.' : `Only ${quantityLabel(remaining)} left.`,
            hint: stock.blocking ? (stock.overrideAvailable ? 'Reduce the quantity, or ask a manager to approve.' : 'Reduce the quantity.') : 'Stock will go below zero.',
        };
    }
    if (after <= BigInt(threshold) * 1000n) {
        return { tone: 'low', text: after === 0n ? 'None left after this sale.' : `${quantityLabel(after)} left after this sale.`, hint: null };
    }

    return { tone: 'ok', text: `${quantityLabel(remaining)} in stock`, hint: null };
}

/** True when blocking is on and some product's cart demand is more than what is there: the server will refuse this basket. */
export function cartIsOverStock(stock, cart) {
    if (!stock.blocking) {
        return false;
    }

    return Object.entries(cartDemand(cart)).some(([productId, demand]) => {
        const tracked = stock.byProduct[productId];

        return tracked !== undefined && demand > tracked.remaining;
    });
}

/**
 * The local refusal of ADDING `extra` thousandths of a product (a tile tap, a scan, a quantity step), or null when it is fine. Only a
 * store that blocks sales without stock refuses. `overrideAvailable` tells the caller whether to offer "ask the manager".
 */
export function checkAdd(stock, cart, product, extra = 1000n) {
    const tracked = stock.blocking ? stock.byProduct[product.id] : undefined;
    if (!tracked) {
        return null;
    }
    const inCart = cartDemand(cart)[product.id] ?? 0n;
    if (inCart + extra <= tracked.remaining) {
        return null;
    }
    const text =
        tracked.remaining <= 0n
            ? `No stock for ${product.name}. A manager must enter stock.`
            : inCart === 0n
              ? `Only ${quantityLabel(tracked.remaining)} left of ${product.name}. Remove one or ask the manager.`
              : `Only ${quantityLabel(tracked.remaining)} left of ${product.name} and ${quantityLabel(inCart)} ${inCart === 1000n ? 'is' : 'are'} already in the cart. Ask the manager if the count is wrong.`;

    return { text, overrideAvailable: stock.overrideAvailable };
}

/**
 * The lines of a refused sale (INSUFFICIENT_STOCK details), or null when the failure was something else. The cashier fixes the cart,
 * removes an item, or (when `overrideAvailable`) asks a manager to approve this one sale.
 */
export function stockRefusal(result) {
    const error = result?.body?.error;
    if (error?.code !== 'INSUFFICIENT_STOCK') {
        return null;
    }

    return {
        overrideAvailable: error.details?.override_available === true,
        shortfalls: (error.details?.shortfalls ?? []).map((line) => ({
            productId: line.product_id,
            name: line.name,
            required: quantityLabel(signedThousandths(line.required) ?? 0n),
            available: quantityLabel(signedThousandths(line.available) ?? 0n),
        })),
    };
}

/** The single-use approval for ONE sale, from stockOverrideGrant's answer. */
export function stockGrantFromResponse(body) {
    return { token: body.token, grantId: body.grant_id, expiresAt: body.expires_at, approverName: body.approver?.name ?? '' };
}

/** True for any of the four STOCK_OVERRIDE_* refusals: the held approval is then no use and is dropped. */
export const isStockOverrideRefusal = (result) => String(result?.body?.error?.code ?? '').startsWith('STOCK_OVERRIDE_');

/**
 * What the cashier reads after the approval request failed. Deliberately says nothing about WHY a code failed beyond "did not work", and
 * nothing about how many tries are left.
 */
export function stockOverrideFailureText(result) {
    const code = result?.body?.error?.code;
    if (code === 'STOCK_OVERRIDE_INVALID') {
        return 'That code did not work.';
    }
    if (code === 'STOCK_OVERRIDE_LOCKED') {
        return result.body.error.message ?? 'Too many wrong codes. This manager is locked for a while. Ask another manager, or wait.';
    }
    if (code === 'STOCK_OVERRIDE_NOT_ALLOWED' || code === 'STOCK_OVERRIDE_EXPIRED') {
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

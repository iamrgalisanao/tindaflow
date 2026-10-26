import { fromThousandths, toThousandths } from './posMoney';

/**
 * The till's side of stock that blocks sales (ADR-017 slice A). Everything here is ADVISORY: it turns GET /api/v1/stock/availability
 * into what the cashier reads on a tile and in the cart, and refuses an add that cannot work, so a hopeless basket is stopped at the
 * counter. The server decides at checkout (INSUFFICIENT_STOCK), on locked rows, and the stock figure here may be up to a minute old.
 * Quantities are whole thousandths (BigInt), never floating point. Prices and costs are not part of this: only quantities.
 *
 * Slice B (daily stock for cooked items) adds DAILY items to the same read. A DAILY product (a snack cooked daily) has its own count for
 * today; an ALA CARTE product linked to a dish and every chosen dish of a meal draw PORTIONS from the dish's pool, so the cart's demand is
 * pooled per dish (a meal's main, an ala carte portion of the same dish and a Combo side add up). Daily items never get the amber "last 3"
 * warning: they just sell out ("N left", "Sold out"). Add-ons (rice) and untracked items never count.
 */

/** Before the first read, or when it failed: no tile or line is marked and nothing is refused locally. */
export const NO_STOCK_RULES = { loaded: false, blocking: false, overrideAvailable: false, defaultThreshold: 3, byProduct: {}, dishes: {}, stockDate: null, dailyConfigured: false, dailyEntered: false };

const dishesOf = (stock) => stock.dishes ?? {};

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
            byProduct[item.product_id] = {
                remaining,
                threshold: Number(item.threshold ?? body.low_stock_default_threshold ?? 3),
                mode: item.mode ?? 'RUNNING',
                dishId: item.dish_id ?? null,
                portion: item.stock_portion ? (signedThousandths(item.stock_portion) ?? 1000n) : null,
            };
        }
    }
    const dishes = {};
    for (const dish of body.dishes ?? []) {
        const remaining = signedThousandths(dish.remaining);
        if (remaining !== null) {
            dishes[dish.dish_id] = { remaining, name: dish.name };
        }
    }

    return {
        loaded: true,
        blocking: body.blocking === true,
        overrideAvailable: body.override_available === true,
        defaultThreshold: Number(body.low_stock_default_threshold ?? 3),
        byProduct,
        dishes,
        stockDate: body.stock_date ?? null,
        dailyConfigured: body.daily_configured === true,
        dailyEntered: body.daily_entered === true,
    };
}

/** "5" or "2.5": a quantity without trailing zeros. */
export function quantityLabel(thousandths) {
    const negative = thousandths < 0n;

    return `${negative ? '-' : ''}${fromThousandths(negative ? -thousandths : thousandths)}`;
}

/** What each tracked product demands across the cart. A meal line demands nothing here (its backing product is not tracked); a dish's pool is {@link poolDemand}. @returns {{[productId: string]: bigint}} */
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

const portionTimes = (quantity, portion) => (quantity * portion) / 1000n;

/**
 * The portions of each daily dish the cart asks for, pooled: an ala carte product linked to the dish takes quantity x its portion, and every chosen
 * dish of a meal line takes the option's portion once per plate. @returns {{[dishId: string]: bigint}}
 */
export function poolDemand(stock, cart) {
    const demand = {};
    const add = (dishId, amount) => {
        demand[dishId] = (demand[dishId] ?? 0n) + amount;
    };
    for (const line of cart) {
        const quantity = toThousandths(line.quantity) ?? 0n;
        if (line.meal) {
            for (const choice of line.meal.choices) {
                if (choice.dish_id && dishesOf(stock)[choice.dish_id] && choice.stock_portion) {
                    add(choice.dish_id, portionTimes(quantity, signedThousandths(choice.stock_portion) ?? 0n));
                }
            }
            continue;
        }
        const tracked = stock.byProduct[line.product.id];
        if (tracked?.dishId && tracked.portion) {
            add(tracked.dishId, portionTimes(quantity, tracked.portion));
        }
    }

    return demand;
}

/** The whole units of an ala carte product that `portions` of its dish cover. */
const unitsFor = (portions, portion) => (portions <= 0n || !portion ? 0n : (portions / portion) * 1000n);

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
    if (tracked.mode === 'DAILY') {
        // Cooked daily: no "last 3" warning, it just sells out. The count is today's.
        return remaining <= 0n ? { tone: 'out', text: 'Sold out', blocked: stock.blocking } : { tone: 'ok', text: `${quantityLabel(remaining)} left`, blocked: false };
    }
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
    if (line.meal) {
        return mealLineStock(stock, cart, line);
    }
    const tracked = stock.byProduct[line.product.id];
    if (!tracked) {
        return null;
    }
    if (tracked.dishId && dishesOf(stock)[tracked.dishId]) {
        return dishLineStock(stock, cart, tracked.dishId, tracked.portion);
    }
    const { remaining, threshold } = tracked;
    const demand = cartDemand(cart)[line.product.id] ?? 0n;
    if (tracked.mode === 'DAILY') {
        const left = remaining - demand;

        return left < 0n ? overLine(stock, remaining <= 0n ? 'Sold out.' : `Only ${quantityLabel(remaining)} left.`) : { tone: 'ok', text: `${quantityLabel(left)} left after this sale`, hint: null };
    }
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

const overLine = (stock, text) => ({
    tone: 'over',
    text,
    hint: stock.blocking ? (stock.overrideAvailable ? 'Reduce the quantity, or ask a manager to approve.' : 'Reduce the quantity.') : 'Stock will go below zero.',
});

/** A line that draws portions from a dish's daily pool: red when the cart's pooled demand is more than is left, else how many units are left after it. */
function dishLineStock(stock, cart, dishId, portion) {
    const remaining = dishesOf(stock)[dishId].remaining;
    const after = remaining - (poolDemand(stock, cart)[dishId] ?? 0n);
    if (after < 0n) {
        return overLine(stock, remaining <= 0n ? 'Sold out.' : `Only ${quantityLabel(unitsFor(remaining, portion ?? 1000n))} left.`);
    }

    return { tone: 'ok', text: `${quantityLabel(unitsFor(after, portion ?? 1000n))} left after this sale`, hint: null };
}

/** A meal line: red naming the first chosen dish whose pool the cart overdraws, else nothing (a plate shows no count of its own). */
function mealLineStock(stock, cart, line) {
    const demand = poolDemand(stock, cart);
    for (const choice of line.meal.choices) {
        const dish = choice.dish_id ? dishesOf(stock)[choice.dish_id] : undefined;
        if (dish && choice.stock_portion && dish.remaining - (demand[choice.dish_id] ?? 0n) < 0n) {
            return overLine(stock, dish.remaining <= 0n ? `${dish.name} is sold out.` : `Only ${quantityLabel(dish.remaining)} portions of ${dish.name} left.`);
        }
    }

    return null;
}

/** True when blocking is on and some product's cart demand is more than what is there: the server will refuse this basket. */
export function cartIsOverStock(stock, cart) {
    if (!stock.blocking) {
        return false;
    }

    const productsOver = Object.entries(cartDemand(cart)).some(([productId, demand]) => {
        const tracked = stock.byProduct[productId];

        return tracked !== undefined && !tracked.dishId && demand > tracked.remaining;
    });

    return productsOver || Object.entries(poolDemand(stock, cart)).some(([dishId, demand]) => demand > (dishesOf(stock)[dishId]?.remaining ?? 0n));
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
    if (tracked.dishId && dishesOf(stock)[tracked.dishId]) {
        const remaining = dishesOf(stock)[tracked.dishId].remaining;
        const inPool = poolDemand(stock, cart)[tracked.dishId] ?? 0n;
        if (inPool + portionTimes(extra, tracked.portion ?? 1000n) <= remaining) {
            return null;
        }
        const left = unitsFor(remaining - inPool, tracked.portion);

        return {
            text:
                remaining <= 0n
                    ? `No stock for ${product.name} today. A manager must enter today's stock.`
                    : left <= 0n
                      ? `${product.name} is sold out: the portions of ${dishesOf(stock)[tracked.dishId].name} that are left are already in the cart. Ask the manager if the count is wrong.`
                      : `Only ${quantityLabel(left)} left of ${product.name}. Remove one or ask the manager.`,
            overrideAvailable: stock.overrideAvailable,
        };
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
 * The local refusal of ADDING one more plate of a finished meal selection (its chosen dishes draw portions from their daily pools), or null when
 * the pools cover it or the store does not block. Names the first dish that cannot be covered.
 */
export function checkAddMeal(stock, cart, mealLine) {
    if (!stock.blocking) {
        return null;
    }
    const demand = poolDemand(stock, cart);
    for (const choice of mealLine.meal.choices) {
        const dish = choice.dish_id ? dishesOf(stock)[choice.dish_id] : undefined;
        if (!dish || !choice.stock_portion) {
            continue;
        }
        const portion = signedThousandths(choice.stock_portion) ?? 0n;
        if ((demand[choice.dish_id] ?? 0n) + portion > dish.remaining) {
            return {
                text:
                    dish.remaining <= 0n
                        ? `${dish.name} is sold out today. A manager must enter today's stock.`
                        : `Only ${quantityLabel(dish.remaining - (demand[choice.dish_id] ?? 0n) > 0n ? dish.remaining - (demand[choice.dish_id] ?? 0n) : 0n)} portions of ${dish.name} left. Choose another dish or ask the manager.`,
                overrideAvailable: stock.overrideAvailable,
            };
        }
    }

    return null;
}

/**
 * How one chosen dish stands for the meal picker: the portions left after the cart, whether this option can still be served, and the label for
 * it ("3 left", "Sold out"). Null for a dish that does not count daily stock.
 */
export function dishAvailability(stock, cart, dishId, portionText) {
    const dish = dishesOf(stock)[dishId];
    if (!dish) {
        return null;
    }
    const portion = signedThousandths(portionText) ?? 1000n;
    const left = dish.remaining - (poolDemand(stock, cart)[dishId] ?? 0n);
    const servings = left <= 0n ? 0n : (left / portion) * 1000n;
    const soldOut = left < portion;

    return { soldOut, blocked: soldOut && stock.blocking, text: soldOut ? 'Sold out' : `${quantityLabel(servings)} left` };
}

/** The cart lines that draw from a dish's pool (what "Remove item" removes for a short dish). @returns {string[]} the line keys */
export function linesDrawingFromDish(stock, cart, dishId) {
    return cart
        .filter((line) => (line.meal ? line.meal.choices.some((choice) => choice.dish_id === dishId && choice.stock_portion) : stock.byProduct[line.product.id]?.dishId === dishId))
        .map((line) => line.key);
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
            dishId: line.dish_id ?? null,
            kind: line.kind ?? 'PRODUCT',
            stockDate: line.stock_date ?? null,
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

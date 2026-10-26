import { describe, expect, it } from 'vitest';
import { NO_STOCK_RULES, cartDemand, cartIsOverStock, checkAdd, isStockOverrideRefusal, lineStock, quantityLabel, stockFromResponse, stockOverrideFailureText, stockRefusal, tileStock } from './stock';

// ADR-017 slice A: the till's advisory view of stock. The server decides; these only turn the availability read into words and refuse a hopeless add.
const availability = (items, rules = {}) => ({ blocking: true, override_available: true, low_stock_default_threshold: 3, items, ...rules });
const item = (product_id, remaining, threshold = 3) => ({ product_id, remaining, threshold, low: false, sold_out: false });
const coke = { id: 'coke', name: 'Coke' };
const line = (product, quantity, extra = {}) => ({ key: product.id, product, quantity, ...extra });

describe('stockFromResponse', () => {
    it('keeps the rules and each product as whole thousandths', () => {
        const stock = stockFromResponse(availability([item('coke', '5.000'), item('soda', '-2.000')]));

        expect(stock).toMatchObject({ loaded: true, blocking: true, overrideAvailable: true, defaultThreshold: 3 });
        expect(stock.byProduct.coke).toEqual({ remaining: 5000n, threshold: 3, mode: 'RUNNING', dishId: null, portion: null });
        expect(stock.byProduct.soda.remaining).toBe(-2000n);
    });

    it('leaves the till without stock marks when the answer is not the expected shape', () => {
        expect(stockFromResponse(null)).toBe(NO_STOCK_RULES);
        expect(stockFromResponse({ data: [] })).toBe(NO_STOCK_RULES);
    });
});

describe('quantityLabel', () => {
    it('drops trailing zeros and keeps the sign', () => {
        expect(quantityLabel(5000n)).toBe('5');
        expect(quantityLabel(2500n)).toBe('2.5');
        expect(quantityLabel(-1000n)).toBe('-1');
    });
});

describe('tileStock', () => {
    const stock = stockFromResponse(availability([item('plenty', '12.000'), item('edge', '3.000'), item('low', '2.000'), item('own', '10.000', 10), item('none', '0.000'), item('minus', '-1.000'), item('zeroLevel', '1.000', 0)]));

    it('is quiet when there is plenty, amber at or below the threshold, and "No stock" at zero or below', () => {
        expect(tileStock(stock, 'plenty')).toEqual({ tone: 'ok', text: '12 in stock', blocked: false });
        expect(tileStock(stock, 'edge')).toEqual({ tone: 'low', text: 'Only 3 left', blocked: false });
        expect(tileStock(stock, 'low')).toEqual({ tone: 'low', text: 'Only 2 left', blocked: false });
        expect(tileStock(stock, 'own').tone).toBe('low'); // its own level of 10 wins
        expect(tileStock(stock, 'none')).toEqual({ tone: 'out', text: 'No stock', blocked: true });
        expect(tileStock(stock, 'minus').tone).toBe('out');
        expect(tileStock(stock, 'zeroLevel').tone).toBe('ok'); // an explicit 0 warns only when sold out
    });

    it('is not tappable only when the store blocks sales, and is not marked at all for an untracked product', () => {
        const lax = stockFromResponse(availability([item('none', '0.000')], { blocking: false, override_available: false }));

        expect(tileStock(lax, 'none')).toEqual({ tone: 'out', text: 'No stock', blocked: false });
        expect(tileStock(stock, 'a-service')).toBeNull();
        expect(tileStock(NO_STOCK_RULES, 'plenty')).toBeNull();
    });
});

describe('the cart', () => {
    const stock = stockFromResponse(availability([item('coke', '5.000'), item('chips', '10.000')]));

    it('adds up the quantities of one product across lines and ignores meals', () => {
        const meal = { ...line({ id: 'backing' }, '2'), meal: { meal_id: 'm' } };

        expect(cartDemand([line(coke, '2'), { ...line(coke, '1.5'), key: 'second' }, meal])).toEqual({ coke: 3500n });
    });

    it('marks a line low when the cart leaves the threshold or less, and over when it asks for more than is there', () => {
        expect(lineStock(stock, [line(coke, '1')], line(coke, '1'))).toMatchObject({ tone: 'ok', text: '5 in stock' });
        expect(lineStock(stock, [line(coke, '2')], line(coke, '2'))).toMatchObject({ tone: 'low', text: '3 left after this sale.' });
        expect(lineStock(stock, [line(coke, '5')], line(coke, '5'))).toMatchObject({ tone: 'low', text: 'None left after this sale.' });
        const over = lineStock(stock, [line(coke, '6')], line(coke, '6'));
        expect(over).toMatchObject({ tone: 'over', text: 'Only 5 left.' });
        expect(over.hint).toContain('ask a manager');
    });

    it('counts the whole cart, not one line, against what is there', () => {
        const cart = [line(coke, '3'), { ...line(coke, '3'), key: 'again' }];

        expect(lineStock(stock, cart, cart[0]).tone).toBe('over');
    });

    it('says "No stock" for a product at zero and does not say "ask a manager" when the store has no override', () => {
        const none = stockFromResponse(availability([item('coke', '0.000')], { override_available: false }));
        const result = lineStock(none, [line(coke, '1')], line(coke, '1'));

        expect(result.text).toBe('No stock.');
        expect(result.hint).toBe('Reduce the quantity.');
    });

    it('with blocking off, an over line only warns that stock will go below zero', () => {
        const lax = stockFromResponse(availability([item('coke', '1.000')], { blocking: false, override_available: false }));

        expect(lineStock(lax, [line(coke, '3')], line(coke, '3')).hint).toBe('Stock will go below zero.');
        expect(cartIsOverStock(lax, [line(coke, '3')])).toBe(false);
    });

    it('knows when the server will refuse the basket', () => {
        expect(cartIsOverStock(stock, [line(coke, '5')])).toBe(false);
        expect(cartIsOverStock(stock, [line(coke, '6')])).toBe(true);
        expect(cartIsOverStock(NO_STOCK_RULES, [line(coke, '600')])).toBe(false);
    });
});

describe('checkAdd', () => {
    const stock = stockFromResponse(availability([item('coke', '2.000'), item('none', '0.000'), item('chips', '9.000')]));

    it('allows an add the stock covers and refuses one it cannot, saying how many are left', () => {
        expect(checkAdd(stock, [], coke)).toBeNull();
        expect(checkAdd(stock, [line(coke, '1')], coke)).toBeNull();
        expect(checkAdd(stock, [], coke, 3000n).text).toBe('Only 2 left of Coke. Remove one or ask the manager.');
        expect(checkAdd(stock, [line(coke, '2')], coke).text).toContain('2 are already in the cart');
    });

    it('says there is no stock at all, and who can fix it', () => {
        expect(checkAdd(stock, [], { id: 'none', name: 'Beef nilaga' }).text).toBe('No stock for Beef nilaga. A manager must enter stock.');
    });

    it('never refuses locally when the store does not block sales, or for an untracked product', () => {
        expect(checkAdd(stockFromResponse(availability([item('none', '0.000')], { blocking: false })), [], { id: 'none', name: 'X' })).toBeNull();
        expect(checkAdd(stock, [], { id: 'a-service', name: 'Haircut' }, 99000n)).toBeNull();
    });

    it('tells the caller whether to offer the manager', () => {
        expect(checkAdd(stock, [], coke, 5000n).overrideAvailable).toBe(true);
        expect(checkAdd(stockFromResponse(availability([item('coke', '2.000')], { override_available: false })), [], coke, 5000n).overrideAvailable).toBe(false);
    });
});

describe('the refusal', () => {
    const result = {
        ok: false,
        status: 409,
        body: { error: { code: 'INSUFFICIENT_STOCK', message: 'x', details: { override_available: true, shortfalls: [{ kind: 'PRODUCT', product_id: 'coke', name: 'Coke', required: '3.000', available: '1.000' }, { product_id: 'chips', name: 'Chips', required: '1.000', available: '-2.000' }] } } },
    };

    it('lists every short line with the quantities as plain numbers', () => {
        expect(stockRefusal(result)).toEqual({
            overrideAvailable: true,
            shortfalls: [
                { productId: 'coke', dishId: null, kind: 'PRODUCT', stockDate: null, name: 'Coke', required: '3', available: '1' },
                { productId: 'chips', dishId: null, kind: 'PRODUCT', stockDate: null, name: 'Chips', required: '1', available: '-2' },
            ],
        });
    });

    it('is null for any other failure', () => {
        expect(stockRefusal({ ok: false, status: 409, body: { error: { code: 'CREDIT_LIMIT_EXCEEDED' } } })).toBeNull();
        expect(stockRefusal({ ok: false, status: 0, body: null })).toBeNull();
    });
});

describe('the approval failure words', () => {
    const failed = (status, code, message) => ({ ok: false, status, body: { error: { code, message } } });

    it('says only "did not work" for a bad code and nothing about tries left', () => {
        expect(stockOverrideFailureText(failed(422, 'STOCK_OVERRIDE_INVALID', 'That code did not work.'))).toBe('That code did not work.');
    });

    it('passes the server\'s plain sentence for a lock, an expiry and a refusal, and knows a dropped connection', () => {
        expect(stockOverrideFailureText(failed(423, 'STOCK_OVERRIDE_LOCKED', 'Locked for 15 minutes.'))).toBe('Locked for 15 minutes.');
        expect(stockOverrideFailureText(failed(409, 'STOCK_OVERRIDE_EXPIRED', 'Expired.'))).toBe('Expired.');
        expect(stockOverrideFailureText(failed(403, 'STOCK_OVERRIDE_NOT_ALLOWED', 'Not for this cart.'))).toBe('Not for this cart.');
        expect(stockOverrideFailureText({ ok: false, status: 0, body: null })).toContain('connection dropped');
        expect(stockOverrideFailureText({ ok: false, status: 429, body: null })).toContain('Too many tries');
    });

    it('recognises the four refusals', () => {
        expect(isStockOverrideRefusal(failed(409, 'STOCK_OVERRIDE_EXPIRED'))).toBe(true);
        expect(isStockOverrideRefusal(failed(409, 'CREDIT_OVERRIDE_EXPIRED'))).toBe(false);
        expect(isStockOverrideRefusal(failed(409, 'INSUFFICIENT_STOCK'))).toBe(false);
    });
});

// ADR-017 slice C: the freshness flag rides along on a running item's tile; it is a note and never makes a tile blocked.
describe('tileStock check freshness', () => {
    const stock = stockFromResponse(availability([{ ...item('old', '5.000'), check_freshness: true }, { ...item('oldLow', '2.000'), check_freshness: true }, item('plain', '5.000')]));

    it('carries the flag on an ok or low tile, never blocks, and is absent otherwise', () => {
        expect(tileStock(stock, 'old')).toEqual({ tone: 'ok', text: '5 in stock', blocked: false, checkFreshness: true });
        expect(tileStock(stock, 'oldLow')).toMatchObject({ tone: 'low', blocked: false, checkFreshness: true });
        expect(tileStock(stock, 'plain').checkFreshness).toBeUndefined();
    });
});

import { describe, expect, it } from 'vitest';
import { checkAdd, checkAddMeal, dishAvailability, lineStock, linesDrawingFromDish, poolDemand, stockFromResponse, stockRefusal, tileStock } from './stock';

// ADR-017 slice B: daily dishes at the till. Portions are pooled per dish across the cart; daily items sell out ("N left", "Sold out") and never get the
// amber "last 3" warning.
const availability = (rules = {}) => ({
    blocking: true,
    override_available: true,
    low_stock_default_threshold: 3,
    stock_date: '2026-10-27',
    daily_configured: true,
    daily_entered: true,
    dishes: [
        { dish_id: 'nilaga', name: 'Beef nilaga', remaining: '5.000', sold_out: false },
        { dish_id: 'pinakbet', name: 'Pinakbet', remaining: '0.000', sold_out: true },
    ],
    items: [
        { product_id: 'ala-nilaga', mode: 'DAILY', dish_id: 'nilaga', stock_portion: '2.000', remaining: '2.000', threshold: 0, low: false, sold_out: false },
        { product_id: 'ala-pinakbet', mode: 'DAILY', dish_id: 'pinakbet', stock_portion: '2.000', remaining: '0.000', threshold: 0, low: false, sold_out: true },
        { product_id: 'siopao', mode: 'DAILY', dish_id: null, stock_portion: null, remaining: '2.000', threshold: 0, low: false, sold_out: false },
        { product_id: 'cola', mode: 'RUNNING', dish_id: null, stock_portion: null, remaining: '2.000', threshold: 3, low: true, sold_out: false },
    ],
    ...rules,
});
const stock = stockFromResponse(availability());
const ala = { id: 'ala-nilaga', name: 'Beef nilaga (ala carte)' };
const line = (product, quantity = '1') => ({ key: product.id, product, quantity });
const plate = (dishId, portion = '1.000', quantity = '1') => ({
    key: `meal|${dishId}`,
    product: { id: `meal:${dishId}`, name: 'Standard Meal' },
    quantity,
    meal: { meal_id: 'm', meal_name: 'Standard Meal', choices: [{ group_id: 'g', option_id: `o-${dishId}`, label: dishId, dish_id: dishId, stock_portion: portion }], addons: [] },
});

describe('the daily read', () => {
    it('keeps the stock day, the dishes and each daily product with its dish link and portion', () => {
        expect(stock).toMatchObject({ loaded: true, stockDate: '2026-10-27', dailyConfigured: true, dailyEntered: true });
        expect(stock.dishes.nilaga).toEqual({ remaining: 5000n, name: 'Beef nilaga' });
        expect(stock.byProduct['ala-nilaga']).toMatchObject({ mode: 'DAILY', dishId: 'nilaga', portion: 2000n, remaining: 2000n });
    });
});

describe('daily tiles', () => {
    it('read "N left" or "Sold out", never the amber low-stock warning', () => {
        expect(tileStock(stock, 'ala-nilaga')).toEqual({ tone: 'ok', text: '2 left', blocked: false });
        expect(tileStock(stock, 'siopao')).toEqual({ tone: 'ok', text: '2 left', blocked: false });
        expect(tileStock(stock, 'ala-pinakbet')).toEqual({ tone: 'out', text: 'Sold out', blocked: true });
        // 2 left of a running product is "low" (amber); 2 left of a daily one is not.
        expect(tileStock(stock, 'cola').tone).toBe('low');
    });

    it('a sold-out daily tile is only a label when the store does not block', () => {
        expect(tileStock(stockFromResponse(availability({ blocking: false })), 'ala-pinakbet')).toEqual({ tone: 'out', text: 'Sold out', blocked: false });
    });
});

describe('pooled demand', () => {
    it('adds a meal, an ala carte order and a second plate of the same dish into one count per dish', () => {
        const cart = [plate('nilaga'), line(ala, '1'), plate('nilaga', '1.000', '2'), plate('pinakbet', '1.000')];

        expect(poolDemand(stock, cart)).toEqual({ nilaga: 5000n, pinakbet: 1000n });
    });

    it('ignores a dish that does not count daily stock and an add-on', () => {
        const cart = [plate('plain', null), { key: 'x', product: { id: 'siopao' }, quantity: '2' }];

        expect(poolDemand(stock, cart)).toEqual({});
    });
});

describe('adding to the cart', () => {
    it('refuses an ala carte order that the pool cannot cover and says how many are left', () => {
        // 5 portions: a plate takes 1, so 4 are left, which is 2 ala carte orders. A third order is refused.
        const cart = [plate('nilaga'), line(ala, '1')];

        expect(checkAdd(stock, cart, ala)).toBeNull(); // 1 + 2 + 2 = 5
        // A fourth portion: 1 + 2 + 1 + 2 would be 6 of 5. Nothing whole is left, so it reads as sold out.
        expect(checkAdd(stock, [...cart, plate('nilaga')], ala).text).toBe('Beef nilaga (ala carte) is sold out: the portions of Beef nilaga that are left are already in the cart. Ask the manager if the count is wrong.');
        // With 4 of 5 portions in plates, 1 is left: not enough for an order of 2.
        expect(checkAdd(stock, [plate('nilaga', '1.000', '4')], ala)).toMatchObject({ overrideAvailable: true });
        expect(checkAdd(stock, [plate('nilaga', '1.000', '4')], ala).text).toContain('sold out');
    });

    it('says no stock for a dish with none entered', () => {
        const refusal = checkAdd(stock, [], { id: 'ala-pinakbet', name: 'Pinakbet (ala carte)' });

        expect(refusal.text).toBe("No stock for Pinakbet (ala carte) today. A manager must enter today's stock.");
    });

    it('refuses a plate whose dish is sold out or short and names the dish, and never refuses when the store does not block', () => {
        expect(checkAddMeal(stock, [], plate('pinakbet')).text).toBe("Pinakbet is sold out today. A manager must enter today's stock.");
        expect(checkAddMeal(stock, [plate('nilaga', '1.000', '5')], plate('nilaga')).text).toMatch(/Beef nilaga/);
        expect(checkAddMeal(stock, [], plate('nilaga'))).toBeNull();
        expect(checkAddMeal(stockFromResponse(availability({ blocking: false })), [], plate('pinakbet'))).toBeNull();
    });
});

describe('cart lines', () => {
    it('shows how many units are left after the cart for an ala carte line and goes red when the cart overdraws the dish', () => {
        expect(lineStock(stock, [line(ala, '1')], line(ala, '1'))).toMatchObject({ tone: 'ok', text: '1 left after this sale' });
        const over = lineStock(stock, [plate('nilaga', '1.000', '4'), line(ala, '1')], line(ala, '1'));
        expect(over).toMatchObject({ tone: 'over', text: 'Only 2 left.' });
    });

    it('marks a plate red naming the dish when the pool is overdrawn, and nothing otherwise', () => {
        expect(lineStock(stock, [plate('nilaga')], plate('nilaga'))).toBeNull();
        expect(lineStock(stock, [plate('pinakbet')], plate('pinakbet'))).toMatchObject({ tone: 'over', text: 'Pinakbet is sold out.' });
    });
});

describe('the meal picker', () => {
    it('reports the portions left after the cart and greys a dish with less than one plate', () => {
        expect(dishAvailability(stock, [], 'nilaga', '1.000')).toEqual({ soldOut: false, blocked: false, text: '5 left' });
        expect(dishAvailability(stock, [plate('nilaga', '1.000', '4')], 'nilaga', '1.000')).toEqual({ soldOut: false, blocked: false, text: '1 left' });
        expect(dishAvailability(stock, [plate('nilaga', '1.000', '5')], 'nilaga', '1.000')).toEqual({ soldOut: true, blocked: true, text: 'Sold out' });
        expect(dishAvailability(stock, [], 'pinakbet', '1.000')).toMatchObject({ soldOut: true, blocked: true });
        expect(dishAvailability(stock, [], 'unknown', '1.000')).toBeNull();
    });
});

describe('a refused sale', () => {
    it('carries the dish of a short line so "Remove item" can take out every line that draws from it', () => {
        const refusal = stockRefusal({
            ok: false, status: 409,
            body: { error: { code: 'INSUFFICIENT_STOCK', details: { override_available: true, shortfalls: [{ kind: 'DISH', product_id: 'pool', dish_id: 'nilaga', name: 'Beef nilaga', stock_date: '2026-10-27', required: '3.000', available: '1.000' }] } } },
        });

        expect(refusal.shortfalls[0]).toMatchObject({ kind: 'DISH', dishId: 'nilaga', stockDate: '2026-10-27', required: '3', available: '1' });
        expect(linesDrawingFromDish(stock, [plate('nilaga'), line(ala), plate('pinakbet'), line({ id: 'cola' })], 'nilaga')).toEqual(['meal|nilaga', 'ala-nilaga']);
    });
});

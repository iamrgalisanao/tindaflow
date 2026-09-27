import { describe, expect, it } from 'vitest';
import { apiMoney, clampedDiscountCents, fromThousandths, lineCents, moneyText, pesos, toCents, toThousandths } from './posMoney';

describe('toCents', () => {
    it('reads whole and two-decimal amounts in centavos', () => {
        expect(toCents('0')).toBe(0n);
        expect(toCents('15')).toBe(1500n);
        expect(toCents('15.5')).toBe(1550n);
        expect(toCents('15.05')).toBe(1505n);
        expect(toCents(' 1300.00 ')).toBe(130000n);
    });

    it('refuses anything that is not a money amount', () => {
        for (const bad of ['', ' ', 'abc', '1.234', '-5', '1,000', '1e3', '.5', '5.', null, undefined]) {
            expect(toCents(bad), String(bad)).toBeNull();
        }
    });
});

describe('quantities', () => {
    it('reads up to three decimals and refuses zero', () => {
        expect(toThousandths('2')).toBe(2000n);
        expect(toThousandths('0.125')).toBe(125n);
        expect(toThousandths('0')).toBeNull();
        expect(toThousandths('0.0001')).toBeNull();
    });

    it('formats thousandths back without trailing zeros', () => {
        expect(fromThousandths(2000n)).toBe('2');
        expect(fromThousandths(125n)).toBe('0.125');
        expect(fromThousandths(1500n)).toBe('1.5');
    });
});

describe('lineCents (never floating point)', () => {
    it('is exact where floats are not: 3 x 0.10 is 30 centavos', () => {
        expect(0.1 * 3).not.toBe(0.3); // the trap this avoids
        expect(lineCents('0.10', '3')).toBe(30n);
    });

    it('rounds half up at the centavo', () => {
        expect(lineCents('0.05', '0.5')).toBe(3n); // 2.5 centavos -> 3
        expect(lineCents('33.33', '3')).toBe(9999n);
        expect(lineCents('10.00', '0.125')).toBe(125n);
    });

    it('is null while a box is half-typed', () => {
        expect(lineCents('', '1')).toBeNull();
        expect(lineCents('10', '')).toBeNull();
    });
});

describe('formatting', () => {
    it('formats pesos with grouping and a sign', () => {
        expect(pesos(130000n)).toBe('1,300.00');
        expect(pesos(5n)).toBe('0.05');
        expect(pesos(-2550n)).toBe('-25.50');
        expect(pesos(123456789n)).toBe('1,234,567.89');
    });

    it('formats the API text for an amount', () => {
        expect(moneyText(130000n)).toBe('1300.00');
        expect(moneyText(5n)).toBe('0.05');
        expect(moneyText(0n)).toBe('0.00');
    });
});

describe('apiMoney: what the till sends for a typed amount (finding #55)', () => {
    it('normalises a valid amount to two decimals without floats', () => {
        expect(apiMoney('100')).toBe('100.00');
        expect(apiMoney('100.5')).toBe('100.50');
        expect(apiMoney(' 1000.05 ')).toBe('1000.05');
    });

    it('does not round through a float: 1.005 would have become "1.00" or "1.01" depending on the float', () => {
        expect(Number('1.005').toFixed(2)).toBe('1.00'); // what the old Number(x).toFixed(2) did to the cashier's figure
        expect(apiMoney('1.005')).toBe('1.005'); // sent as typed, so the server refuses it with a field error
    });

    it('never sends NaN for a half-typed box', () => {
        expect(Number('abc').toFixed(2)).toBe('NaN'); // the old behaviour
        expect(apiMoney('abc')).toBe('abc');
        expect(apiMoney('')).toBe('');
        expect(apiMoney(undefined)).toBe('');
    });
});

describe('clampedDiscountCents', () => {
    it('previews a blank, invalid or negative discount as none, and never more than the amount', () => {
        expect(clampedDiscountCents('', 10000n)).toBe(0n);
        expect(clampedDiscountCents('abc', 10000n)).toBe(0n);
        expect(clampedDiscountCents('5.00', 10000n)).toBe(500n);
        expect(clampedDiscountCents('500.00', 10000n)).toBe(10000n);
    });
});

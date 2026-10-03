import { describe, expect, it } from 'vitest';
import { canonicalMoney, displayMoney, padMoney, parseTyped, positionAfter, significantBefore } from './moneyInput';

describe('parseTyped', () => {
    it('keeps digits and one dot, whatever else is pasted', () => {
        expect(parseTyped('₱1,234.50')).toBe('1234.50');
        expect(parseTyped('abc')).toBe('');
        expect(parseTyped('1.2.3')).toBe('1.23');
        expect(parseTyped('-5')).toBe('5');
    });

    it('allows at most two decimals and ten whole digits', () => {
        expect(parseTyped('10.999')).toBe('10.99');
        expect(parseTyped('123456789012')).toBe('1234567890');
    });

    it('turns a leading dot into 0. and drops leading zeros', () => {
        expect(parseTyped('.5')).toBe('0.5');
        expect(parseTyped('007')).toBe('7');
        expect(parseTyped('0')).toBe('0');
        expect(parseTyped('0.')).toBe('0.');
    });

    it('keeps a trailing dot while the cashier is still typing', () => {
        expect(parseTyped('1000.')).toBe('1000.');
    });
});

describe('canonicalMoney and padMoney', () => {
    it('hands the screen text without a dangling dot', () => {
        expect(canonicalMoney('1000.')).toBe('1000');
        expect(canonicalMoney('')).toBe('');
    });

    it('pads to two decimals on leaving the box, and leaves an empty box empty', () => {
        expect(padMoney('1000')).toBe('1000.00');
        expect(padMoney('1000.')).toBe('1000.00');
        expect(padMoney('5.5')).toBe('5.50');
        expect(padMoney('5.55')).toBe('5.55');
        expect(padMoney('')).toBe('');
    });

    it('never goes through a float: a long amount keeps every digit', () => {
        expect(padMoney('9999999999.9')).toBe('9999999999.90');
    });
});

describe('displayMoney', () => {
    it('shows the peso sign and thousands separators', () => {
        expect(displayMoney('1000')).toBe('₱1,000');
        expect(displayMoney('1234567.5')).toBe('₱1,234,567.5');
        expect(displayMoney('1000.00')).toBe('₱1,000.00');
        expect(displayMoney('1000.')).toBe('₱1,000.');
        expect(displayMoney('0.5')).toBe('₱0.5');
    });

    it('can leave the symbol off where the screen already draws one', () => {
        expect(displayMoney('1000.00', { symbol: false })).toBe('1,000.00');
    });

    it('shows nothing for an empty box so the placeholder can show', () => {
        expect(displayMoney('')).toBe('');
    });
});

describe('caret helpers', () => {
    it('counts only digits and dots before the caret', () => {
        expect(significantBefore('₱1,23', 5)).toBe(3);
        expect(significantBefore('₱1,23', 0)).toBe(0);
    });

    it('puts the caret after the same digit once commas appear', () => {
        // typed the 4th digit at the end of "₱123" -> "₱1,234": the caret belongs after the 4
        expect(positionAfter('₱1,234', 4)).toBe(6);
        // caret after the 1st digit stays before the comma
        expect(positionAfter('₱1,234', 1)).toBe(2);
        expect(positionAfter('₱1,234', 0)).toBe(1);
        expect(positionAfter('1,234', 0, { symbol: false })).toBe(0);
    });
});

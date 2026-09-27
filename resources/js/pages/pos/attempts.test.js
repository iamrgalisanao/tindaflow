import { beforeEach, describe, expect, it, vi } from 'vitest';
import { attemptKey, CONNECTION_DROPPED, failureText, outcomeUnknown, SESSION_EXPIRED, settleAttempt } from './attempts';

let counter;
beforeEach(() => {
    counter = 0;
    vi.stubGlobal('crypto', { randomUUID: () => `key-${++counter}` });
});

const sale = { items: [{ product_id: 'p1', quantity: '2' }], payments: [{ method: 'CASH', amount: '200.00' }] };

describe('attemptKey: one Idempotency-Key per intent, not per click', () => {
    it('gives the SAME key when the same request is sent again (a retry after a dropped connection)', () => {
        const attempts = { current: {} };

        const first = attemptKey(attempts, 'sale', '/api/v1/sales', sale);
        const again = attemptKey(attempts, 'sale', '/api/v1/sales', structuredClone(sale));

        expect(again).toBe(first);
    });

    it('gives a NEW key as soon as anything in the request changes (the server refuses one key for two requests)', () => {
        const attempts = { current: {} };
        const first = attemptKey(attempts, 'sale', '/api/v1/sales', sale);

        expect(attemptKey(attempts, 'sale', '/api/v1/sales', { ...sale, payments: [{ method: 'CASH', amount: '250.00' }] })).not.toBe(first);
        expect(attemptKey(attempts, 'sale', '/api/v1/other', sale)).not.toBe(first);
    });

    it('keeps separate scopes separate', () => {
        const attempts = { current: {} };

        expect(attemptKey(attempts, 'sale', '/api/v1/sales', sale)).not.toBe(attemptKey(attempts, 'close-shift', '/api/v1/shifts/1/close', null));
    });
});

describe('outcomeUnknown: when the server may or may not have saved it', () => {
    it.each([
        [0, true],
        [502, true],
        [500, true],
        [503, true],
        [409, true],
        [200, false],
        [201, false],
        [401, false],
        [403, false],
        [404, false],
        [422, false],
        [429, false],
    ])('status %i -> %s', (status, unknown) => {
        expect(outcomeUnknown({ status })).toBe(unknown);
    });
});

describe('settleAttempt', () => {
    it('keeps the key after an unknown outcome so pressing again is the same attempt', () => {
        const attempts = { current: {} };
        const key = attemptKey(attempts, 'sale', '/api/v1/sales', sale);

        settleAttempt(attempts, 'sale', { status: 0 });

        expect(attemptKey(attempts, 'sale', '/api/v1/sales', sale)).toBe(key);
    });

    it('drops the key after a definite answer, so the next sale is a new one', () => {
        const attempts = { current: {} };
        const key = attemptKey(attempts, 'sale', '/api/v1/sales', sale);

        settleAttempt(attempts, 'sale', { status: 201 });

        expect(attemptKey(attempts, 'sale', '/api/v1/sales', sale)).not.toBe(key);
    });

    it('drops the key after a 401: an expired session saved nothing', () => {
        const attempts = { current: {} };
        const key = attemptKey(attempts, 'sale', '/api/v1/sales', sale);

        settleAttempt(attempts, 'sale', { status: 401 });

        expect(attemptKey(attempts, 'sale', '/api/v1/sales', sale)).not.toBe(key);
    });
});

describe('failureText', () => {
    it('tells the cashier what happened and what to do', () => {
        expect(failureText({ status: 401 }, 'x')).toBe(SESSION_EXPIRED);
        expect(failureText({ status: 0 }, 'x')).toBe(CONNECTION_DROPPED);
        expect(failureText({ status: 502, body: null }, 'x')).toBe(CONNECTION_DROPPED);
    });

    it('otherwise shows the server message, then the fallback', () => {
        expect(failureText({ status: 422, body: { error: { message: 'Amount is too small.' } } }, 'x')).toBe('Amount is too small.');
        expect(failureText({ status: 422, body: null }, 'Checkout failed.')).toBe('Checkout failed.');
    });
});

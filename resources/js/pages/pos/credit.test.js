import { describe, expect, it } from 'vitest';
import { creditSaleParts, isOverLimit, limitRefusal, lookupFailureText, money, payerFromLookup, periodText, remainingAfter, remainingCents, typeLabel } from './credit';

const lookup = {
    id: 'e1', employee_code: 'TSC00012', employee_type: 'DIRECT', name: 'Ana Reyes', active: true,
    credit: { limit: '1500.00', used: '1400.00', remaining: '100.00', period_start: '2026-10-16', period_end: '2026-10-31' },
};

describe('credit helpers', () => {
    it('turns the lookup answer into the payer the register holds', () => {
        expect(payerFromLookup(lookup)).toEqual({
            id: 'e1', code: 'TSC00012', type: 'DIRECT', name: 'Ana Reyes',
            credit: { limit: '1500.00', used: '1400.00', remaining: '100.00', periodStart: '2026-10-16', periodEnd: '2026-10-31' },
        });
    });

    it('works in whole centavos, exactly at the limit and one centavo over', () => {
        const { credit } = payerFromLookup(lookup);
        expect(remainingCents(credit)).toBe(10000n);
        expect(remainingAfter(credit, 10000n)).toBe(0n);
        expect(isOverLimit(credit, 10000n)).toBe(false);
        expect(isOverLimit(credit, 10001n)).toBe(true);
        expect(remainingAfter(credit, 2500n)).toBe(7500n);
    });

    it('reads a negative remaining (a limit lowered below what is used)', () => {
        expect(remainingCents({ remaining: '-50.00' })).toBe(-5000n);
        expect(isOverLimit({ remaining: '-50.00' }, 1n)).toBe(true);
        expect(remainingCents(null)).toBeNull();
        expect(isOverLimit(null, 100n)).toBe(false);
    });

    it('builds the credit payment as the whole total plus the employee', () => {
        expect(creditSaleParts('e1', 12345n)).toEqual({ payments: [{ method: 'CREDIT', amount: '123.45' }], employee_id: 'e1' });
    });

    it('says TSC or SUB and formats the period and money', () => {
        expect(typeLabel('DIRECT')).toBe('TSC');
        expect(typeLabel('SUBCONTRACTOR')).toBe('SUB');
        expect(periodText('2026-10-16', '2026-10-31')).toBe('Oct 16 – Oct 31');
        expect(periodText('2027-02-01', '2027-02-15')).toBe('Feb 1 – Feb 15');
        expect(money('1500.00')).toBe('₱1,500.00');
        expect(money('-50.00')).toBe('-₱50.00');
    });

    it('explains each way a lookup can fail', () => {
        expect(lookupFailureText({ status: 404, body: { error: { code: 'EMPLOYEE_NOT_FOUND' } } })).toMatch(/No employee has this code/);
        expect(lookupFailureText({ status: 409, body: { error: { code: 'EMPLOYEE_INACTIVE' } } })).toMatch(/inactive/);
        expect(lookupFailureText({ status: 429, body: { error: { code: 'RATE_LIMITED' } } })).toMatch(/Too many/);
        expect(lookupFailureText({ status: 0, body: null })).toMatch(/connection/);
    });

    it('reads the figures of a refused credit sale, and nothing from another failure', () => {
        const refusal = limitRefusal({ status: 409, body: { error: { code: 'CREDIT_LIMIT_EXCEEDED', details: { limit: '1500.00', used: '1400.00', remaining: '100.00', requested: '100.01', period_start: '2026-10-01', period_end: '2026-10-15', employee_code: 'TSC00031' } } } });
        expect(refusal).toEqual({ limit: '1500.00', used: '1400.00', remaining: '100.00', requested: '100.01', periodStart: '2026-10-01', periodEnd: '2026-10-15', employeeCode: 'TSC00031' });
        expect(limitRefusal({ status: 409, body: { error: { code: 'SHIFT_REQUIRED' } } })).toBeNull();
        expect(limitRefusal({ status: 0, body: null })).toBeNull();
    });
});

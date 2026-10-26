import { describe, expect, it } from 'vitest';
import {
    PERIOD_STATUS,
    canWriteOff,
    checkAllocations,
    chequePayload,
    emptyChequeForm,
    emptyWriteOffForm,
    fromCents,
    newRequestId,
    openPeriods,
    periodStatusLabel,
    proposeAllocation,
    returnedChequesOf,
    toCents,
    validateCheque,
    validateWriteOff,
    writeOffPayload,
    writeOffStatusLabel,
} from './chequeConfig';

const periods = [
    { period_start: '2026-10-16', owed_amount: '300.00' },
    { period_start: '2026-10-01', owed_amount: '500.00' },
    { period_start: '2026-09-16', owed_amount: '0.00' },
    { period_start: '2026-09-01', owed_amount: '-50.00' },
];

describe('money in centavos', () => {
    it('converts both ways without floating point', () => {
        expect(toCents('12500.5')).toBe(1250050n);
        expect(toCents('0.10') + toCents('0.20')).toBe(30n);
        expect(toCents('abc')).toBeNull();
        expect(fromCents(30n)).toBe('0.30');
        expect(fromCents(5n)).toBe('0.05');
        expect(fromCents(-1250050n)).toBe('-12500.50');
    });
});

describe('proposeAllocation', () => {
    it('lists only the periods that owe something, oldest first', () => {
        expect(openPeriods(periods).map((period) => period.period_start)).toEqual(['2026-10-01', '2026-10-16']);
    });

    it('pays the oldest period first and spills over to the next', () => {
        expect(proposeAllocation(periods, '600.00')).toEqual({ allocations: { '2026-10-01': '500.00', '2026-10-16': '100.00' }, leftover: '0.00' });
    });

    it('puts a small cheque entirely on the oldest period', () => {
        expect(proposeAllocation(periods, '120.50')).toEqual({ allocations: { '2026-10-01': '120.50' }, leftover: '0.00' });
    });

    it('reports what does not fit when the cheque is more than is owed', () => {
        expect(proposeAllocation(periods, '1000.00')).toEqual({ allocations: { '2026-10-01': '500.00', '2026-10-16': '300.00' }, leftover: '200.00' });
    });

    it('proposes nothing for a blank, zero or invalid amount', () => {
        for (const amount of ['', '0', 'x', '-5']) {
            expect(proposeAllocation(periods, amount)).toEqual({ allocations: {}, leftover: '0.00' });
        }
    });
});

describe('checkAllocations', () => {
    it('matches when the typed amounts add up to the cheque and none is more than owed', () => {
        const check = checkAllocations(periods, '600.00', { '2026-10-01': '400', '2026-10-16': '200.00' });
        expect(check).toMatchObject({ sum: '600.00', difference: '0.00', rowErrors: {}, matches: true });
    });

    it('shows how much is still to place when the sum is short or over', () => {
        expect(checkAllocations(periods, '600.00', { '2026-10-01': '400.00' })).toMatchObject({ sum: '400.00', difference: '200.00', matches: false });
        expect(checkAllocations(periods, '300.00', { '2026-10-01': '400.00' })).toMatchObject({ difference: '-100.00', matches: false });
    });

    it('flags a period given more than it owes, and one that owes nothing', () => {
        const check = checkAllocations(periods, '700.00', { '2026-10-01': '600.00', '2026-09-16': '100.00' });
        expect(check.rowErrors['2026-10-01']).toMatch(/Only .*500\.00 is owed/);
        expect(check.rowErrors['2026-09-16']).toBe('Nothing is owed for this period.');
        expect(check.matches).toBe(false);
    });

    it('ignores blank rows and rejects junk', () => {
        expect(checkAllocations(periods, '100.00', { '2026-10-01': '100.00', '2026-10-16': '' }).matches).toBe(true);
        expect(checkAllocations(periods, '100.00', { '2026-10-01': 'abc' }).rowErrors['2026-10-01']).toBe('Enter an amount in pesos.');
        expect(checkAllocations(periods, '', { '2026-10-01': '100.00' }).matches).toBe(false);
    });
});

describe('validateCheque and chequePayload', () => {
    const now = new Date('2026-11-02T01:00:00Z'); // 09:00 on 2 Nov in Manila
    const good = { ...emptyChequeForm(now), chequeNumber: '0012345', bank: 'BDO', amount: '600.00' };

    it('accepts a complete cheque', () => {
        expect(validateCheque(good, now)).toEqual({});
    });

    it('asks for what is missing or wrong', () => {
        const errors = validateCheque({ ...good, chequeNumber: '', bank: ' ', amount: '0', receivedOn: '2026-11-03', chequeDate: 'soon' }, now);
        expect(Object.keys(errors).sort()).toEqual(['amount', 'bank', 'chequeDate', 'chequeNumber', 'receivedOn']);
    });

    it('allows a post-dated cheque but not a date a year away', () => {
        expect(validateCheque({ ...good, chequeDate: '2026-12-15' }, now)).toEqual({});
        expect(validateCheque({ ...good, chequeDate: '2024-11-02' }, now).chequeDate).toMatch(/more than a year/);
    });

    it('builds the request with the allocated periods only, oldest first', () => {
        const payload = chequePayload({ ...good, notes: '  Oct payroll ' }, { '2026-10-16': '200', '2026-10-01': '400.00', '2026-09-01': '' });
        expect(payload).toEqual({
            cheque_number: '0012345', bank: 'BDO', cheque_date: '2026-11-02', amount: '600.00', received_on: '2026-11-02', notes: 'Oct payroll',
            allocations: [{ period_start: '2026-10-01', amount: '400.00' }, { period_start: '2026-10-16', amount: '200.00' }],
        });
    });
});

describe('write-offs (slice 4.3)', () => {
    const period = { period_start: '2026-10-01', owed_amount: '300.00', cheques: [
        { cheque_id: 'a', status: 'RETURNED', bank: 'BDO', cheque_number: '1', amount: '100.00' },
        { cheque_id: 'b', status: 'RECEIVED', bank: 'BPI', cheque_number: '2', amount: '50.00' },
    ] };

    it('offers a write-off only where something is owed', () => {
        expect(canWriteOff(period)).toBe(true);
        expect(canWriteOff({ owed_amount: '0.00' })).toBe(false);
        expect(canWriteOff({ owed_amount: '-5.00' })).toBe(false);
        expect(canWriteOff(undefined)).toBe(false);
    });

    it('defaults the amount to what is owed and offers only returned cheques', () => {
        expect(emptyWriteOffForm(period)).toEqual({ amount: '300.00', reason: '', chequeId: '' });
        expect(emptyWriteOffForm(period, 'a').chequeId).toBe('a');
        expect(returnedChequesOf(period).map((cheque) => cheque.cheque_id)).toEqual(['a']);
    });

    it('requires a reason and an amount between zero and what is owed', () => {
        expect(validateWriteOff({ amount: '300.00', reason: 'Will not pay' }, period)).toEqual({});
        expect(validateWriteOff({ amount: '300.01', reason: 'Will not pay' }, period).amount).toMatch(/Only .*300\.00 is owed/);
        expect(validateWriteOff({ amount: '0', reason: 'Will not pay' }, period).amount).toMatch(/more than zero/);
        expect(validateWriteOff({ amount: '', reason: 'Will not pay' }, period).amount).toBeTruthy();
        expect(validateWriteOff({ amount: '10', reason: '  ' }, period).reason).toBeTruthy();
        expect(validateWriteOff({ amount: '10', reason: 'ab' }, period).reason).toBeTruthy();
        expect(validateWriteOff({ amount: '10', reason: 'abc' }, { owed_amount: '0.00' }).amount).toBe('Nothing is owed for this period.');
    });

    it('builds the request body with two-decimal money, a trimmed reason and the request id', () => {
        expect(writeOffPayload({ amount: '120.5', reason: ' TSC will not pay ', chequeId: 'a' }, period, 'rid')).toEqual({
            period_start: '2026-10-01', amount: '120.50', reason: 'TSC will not pay', cheque_id: 'a', request_id: 'rid',
        });
        expect(writeOffPayload({ amount: '1', reason: 'abc', chequeId: '' }, period, 'rid').cheque_id).toBeNull();
    });

    it('makes a different id each time, shaped like a uuid', () => {
        const first = newRequestId();
        expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/);
        expect(newRequestId()).not.toBe(first);
    });

    it('has plain labels for the new period states and the list rows', () => {
        expect(periodStatusLabel('WRITTEN_OFF')).toBe('Written off');
        expect(periodStatusLabel('OVER_WRITTEN_OFF')).toBe('Written off too much');
        expect(PERIOD_STATUS.WRITTEN_OFF.tone).toBe('violet');
        expect(writeOffStatusLabel({ kind: 'WRITE_OFF', status: 'ACTIVE' })).toBe('Written off');
        expect(writeOffStatusLabel({ kind: 'WRITE_OFF', status: 'REVERSED' })).toBe('Reversed');
        expect(writeOffStatusLabel({ kind: 'REVERSAL', status: null })).toBe('Reversal');
    });
});

import { describe, expect, it } from 'vitest';
import { salePayments } from './salePayments';

describe('salePayments', () => {
    it('sends a typed reference, trimmed, with a method that takes one', () => {
        for (const method of ['GCASH', 'MAYA', 'BANK_TRANSFER']) {
            expect(salePayments([{ method, amount: '68', reference: '  IBFT 0045 ' }])).toEqual([{ method, amount: '68.00', external_reference: 'IBFT 0045' }]);
        }
    });

    it('sends no reference when none was typed', () => {
        for (const reference of [undefined, '', '   ']) {
            expect(salePayments([{ method: 'BANK_TRANSFER', amount: '68.00', reference }])).toEqual([{ method: 'BANK_TRANSFER', amount: '68.00' }]);
        }
    });

    it('never sends a reference with a method that does not take one', () => {
        for (const method of ['CASH', 'CARD', 'OTHER']) {
            expect(salePayments([{ method, amount: '68.00', reference: 'left over' }])).toEqual([{ method, amount: '68.00' }]);
        }
    });

    it('leaves out blank and zero rows and keeps nothing of the screen-only fields', () => {
        const rows = [
            { method: 'CASH', amount: '20.00', source: 'chips' },
            { method: 'GCASH', amount: '', reference: 'x' },
            { method: 'MAYA', amount: '0' },
            { method: 'BANK_TRANSFER', amount: '48.00', reference: 'A1', source: 'prefill' },
        ];

        expect(salePayments(rows)).toEqual([
            { method: 'CASH', amount: '20.00' },
            { method: 'BANK_TRANSFER', amount: '48.00', external_reference: 'A1' },
        ]);
    });
});

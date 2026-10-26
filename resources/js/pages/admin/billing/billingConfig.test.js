import { describe, expect, it } from 'vitest';
import {
    accountIds,
    asOfInstant,
    currentCutoff,
    cutoffRange,
    daysBetween,
    escapeHtml,
    negativeMoney,
    printDocument,
    statementParams,
    toQuery,
    toggleAllIds,
    toggleId,
    validateRange,
} from './billingConfig';

const statement = {
    party: { name: 'TSC' },
    criteria: { period_label: 'Oct 1-15, 2026', from: '2026-10-01', to: '2026-10-15', as_of: '2026-10-26T10:00:00+08:00', employee_type: null, employee_code: null },
    generated_at: '2026-10-26T10:00:00+08:00',
    accounts: [
        {
            employee_id: 'a', employee_code: 'TSC00001', employee_name: '<b>Ana</b> & "Co"', employee_type: 'DIRECT', sold_amount: '83.00', returned_amount: '0.00', net_amount: '83.00',
            meal_types: [{ meal_type: 'Standard Meal', sold_amount: '83.00', returned_amount: '0.00', net_amount: '83.00', items: [
                { item: 'Beef nilaga', kind: 'DISH', quantity_sold: '1.000', sold_amount: '68.00', returned_amount: '0.00', net_amount: '68.00' },
                { item: 'Whole rice', kind: 'ADDON', quantity_sold: '1.000', sold_amount: '15.00', returned_amount: '0.00', net_amount: '15.00' },
            ] }],
        },
        { employee_id: 'b', employee_code: 'SUB00001', employee_name: 'Ben', employee_type: 'SUBCONTRACTOR', sold_amount: '10.00', returned_amount: '0.00', net_amount: '10.00', meal_types: [] },
    ],
    adjustments: [{ entry_id: 'x', type: 'REFUND', recorded_at: '2026-10-20T10:00:00+08:00', employee_code: 'TSC00001', employee_name: 'Ana', sold_on: '2026-10-05', document_reference: '000123', reason: 'Wrong <order>', amount: '15.00' }],
    totals: { sold_amount: '93.00', returned_amount: '15.00', net_amount: '78.00', employee_count: 2, sale_count: 2 },
};

describe('cut-off helpers', () => {
    it('ends the second half on the real last day of the month', () => {
        expect(cutoffRange('2026-02', '2')).toEqual({ from: '2026-02-16', to: '2026-02-28' });
        expect(cutoffRange('2028-02', '2')).toEqual({ from: '2028-02-16', to: '2028-02-29' });
        expect(cutoffRange('2026-04', 2)).toEqual({ from: '2026-04-16', to: '2026-04-30' });
        expect(cutoffRange('2026-10', '2')).toEqual({ from: '2026-10-16', to: '2026-10-31' });
        expect(cutoffRange('2026-10', '1')).toEqual({ from: '2026-10-01', to: '2026-10-15' });
        expect(cutoffRange('nope', '1')).toBeNull();
        expect(cutoffRange('2026-13', '1')).toBeNull();
    });

    it('decides the current cut-off by the Manila date, not the browser zone', () => {
        // 2026-10-15 23:30 in Manila is 15:30 UTC; 2026-10-16 00:30 in Manila is 16:30 UTC the day before.
        expect(currentCutoff(new Date('2026-10-15T15:30:00Z'))).toEqual({ month: '2026-10', half: '1' });
        expect(currentCutoff(new Date('2026-10-15T16:30:00Z'))).toEqual({ month: '2026-10', half: '2' });
        expect(currentCutoff(new Date('2026-10-31T16:30:00Z'))).toEqual({ month: '2026-11', half: '1' });
    });

    it('counts a range inclusively and refuses a backwards or too long one', () => {
        expect(daysBetween('2026-10-01', '2026-10-01')).toBe(1);
        expect(daysBetween('2028-01-01', '2028-12-31')).toBe(366);
        expect(validateRange({ mode: 'custom', from: '2026-10-20', to: '2026-10-01' }).from).toMatch(/cannot be after/);
        expect(validateRange({ mode: 'custom', from: '2026-01-01', to: '2027-01-02' }).to).toMatch(/at most 366 days/);
        expect(validateRange({ mode: 'custom', from: '2028-01-01', to: '2028-12-31' })).toEqual({});
        expect(validateRange({ mode: 'custom', from: '', to: '' })).toEqual({ from: 'Enter the first date.', to: 'Enter the last date.' });
        expect(validateRange({ mode: 'cutoff', month: '2026-10', half: '1' })).toEqual({});
        expect(validateRange({ mode: 'cutoff', month: '', half: '1' })).toEqual({ month: 'Pick the month.' });
    });
});

describe('request parameters', () => {
    it('sends a cut-off or dates, never both, and leaves blank filters out', () => {
        expect(statementParams({ mode: 'cutoff', month: '2026-10', half: '2', from: '2026-01-01', to: '2026-01-02', employeeType: '', employeeId: '', asOf: '' })).toEqual({ cutoff_month: '2026-10', cutoff_half: '2' });
        expect(statementParams({ mode: 'custom', month: '2026-10', half: '2', from: '2026-10-05', to: '2026-10-20', employeeType: 'SUBCONTRACTOR', employeeId: 'e1', asOf: '2026-10-26T14:30' })).toEqual({
            from: '2026-10-05', to: '2026-10-20', employee_type: 'SUBCONTRACTOR', employee_id: 'e1', as_of: '2026-10-26T14:30:00+08:00',
        });
        expect(asOfInstant('')).toBeNull();
        expect(toQuery({ as_of: '2026-10-26T14:30:00+08:00' })).toBe('as_of=2026-10-26T14%3A30%3A00%2B08%3A00');
    });
});

describe('statement helpers', () => {
    it('shows a return as a deduction and zero plainly', () => {
        expect(negativeMoney('100.00')).toBe('-₱100.00');
        expect(negativeMoney('0.00')).toBe('₱0.00');
        expect(negativeMoney('-5.00')).toBe('-₱5.00');
    });

    it('expands everything when something is collapsed and collapses when all are open', () => {
        expect(accountIds(statement)).toEqual(['a', 'b']);
        const opened = toggleAllIds(statement, new Set(['a']));
        expect([...opened]).toEqual(['a', 'b']);
        expect(toggleAllIds(statement, opened).size).toBe(0);
        expect([...toggleId(new Set(), 'a')]).toEqual(['a']);
        expect(toggleId(new Set(['a']), 'a').size).toBe(0);
    });

    it('builds a printable document that escapes every value and carries the run number and totals', () => {
        const html = printDocument(statement, { run_number: 'BR-000007' });
        expect(html).toContain('BR-000007');
        expect(html).toContain('Standard Meal');
        expect(html).toContain('Whole rice (add-on)');
        expect(html).toContain('&lt;b&gt;Ana&lt;/b&gt; &amp; &quot;Co&quot;');
        expect(html).not.toContain('<b>Ana</b>');
        expect(html).toContain('Wrong &lt;order&gt;');
        expect(html).toContain('TOTAL DUE ₱78.00');
        expect(html).toContain('-₱15.00');
        expect(html).not.toContain('<script');
        expect(printDocument(statement)).toContain('Not saved');
        expect(escapeHtml(null)).toBe('');
    });
});

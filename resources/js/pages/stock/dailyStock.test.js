import { describe, expect, it } from 'vitest';
import { approvalFailureText, buildLines, copyYesterday, groupRows, parseQuantity, rowKey, saveFailureText } from './dailyStock';

const row = (kind, id, name, extra = {}) => ({ kind, id, name, group: kind === 'DISH' ? 'Beef' : null, cooked: '0.000', sold: '0.000', remaining: '0.000', yesterday_cooked: '0.000', ...extra });
const rows = [row('DISH', 'nilaga', 'Beef nilaga', { cooked: '20.000', remaining: '14.000', yesterday_cooked: '18.000' }), row('DISH', 'adobo', 'Beef adobo', { yesterday_cooked: '12.000' }), row('PRODUCT', 'siopao', 'Siopao')];

describe('quantities', () => {
    it('accepts whole numbers and up to three decimals only', () => {
        expect(parseQuantity('20')).toBe(20000n);
        expect(parseQuantity('2.5')).toBe(2500n);
        expect(parseQuantity('')).toBeNull();
        expect(parseQuantity('2.5555')).toBeNull();
        expect(parseQuantity('-1')).toBeNull();
        expect(parseQuantity('abc')).toBeNull();
    });
});

describe('the lines a save sends', () => {
    it('sends a changed total as cooked and a second batch as add, and nothing for an untouched row or an unchanged total', () => {
        const { lines, lowers, errors } = buildLines(rows, { 'DISH:nilaga': '20', 'DISH:adobo': '15' }, { 'PRODUCT:siopao': '30' });

        expect(lines).toEqual([{ dish_id: 'adobo', cooked: '15' }, { product_id: 'siopao', add: '30' }]);
        expect(lowers).toBe(false);
        expect(errors).toEqual({});
    });

    it('flags a lowered total so the screen asks why', () => {
        expect(buildLines(rows, { 'DISH:nilaga': '15' }, {}).lowers).toBe(true);
    });

    it('refuses both boxes on one row, a bad number and an add of zero', () => {
        const { lines, errors } = buildLines(rows, { 'DISH:adobo': '5', 'DISH:nilaga': 'x' }, { 'DISH:adobo': '2', 'PRODUCT:siopao': '0' });

        expect(lines).toEqual([]);
        expect(Object.keys(errors).sort()).toEqual(['DISH:adobo', 'DISH:nilaga', 'PRODUCT:siopao']);
    });
});

describe('copy yesterday', () => {
    it("fills only the rows with nothing cooked today and something cooked yesterday; it saves nothing", () => {
        expect(copyYesterday(rows)).toEqual({ 'DISH:adobo': '12' });
    });
});

describe('the grid layout and the words', () => {
    it('groups dishes under their category and plain daily items under "Other items"', () => {
        expect(groupRows(rows).map((group) => [group.name, group.rows.map(rowKey)])).toEqual([
            ['Beef', ['DISH:nilaga', 'DISH:adobo']],
            ['Other items', ['PRODUCT:siopao']],
        ]);
    });

    it('says nothing about why a code failed, and names the dish that cannot go below what is sold', () => {
        const failed = (status, code, message) => ({ ok: false, status, body: { error: { code, message } } });
        expect(approvalFailureText(failed(422, 'STOCK_ENTRY_APPROVAL_INVALID', 'x'))).toBe('That code did not work.');
        expect(approvalFailureText(failed(423, 'STOCK_ENTRY_APPROVAL_LOCKED', 'Locked for 15 minutes.'))).toBe('Locked for 15 minutes.');
        expect(approvalFailureText({ ok: false, status: 429, body: null })).toMatch(/Too many tries/);
        expect(saveFailureText({ ok: false, status: 422, body: { error: { code: 'DAILY_STOCK_BELOW_SOLD', details: { items: [{ name: 'Beef nilaga' }] } } } })).toBe('Beef nilaga cannot go below what is already sold today. Nothing was saved.');
        expect(saveFailureText({ ok: false, status: 0, body: null })).toMatch(/not be counted twice/);
    });
});

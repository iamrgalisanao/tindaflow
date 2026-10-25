import { describe, expect, it } from 'vitest';
import { chunkCards, employeeBody, employeeQuery, formatLimit, importHeadline, joinName, normalizeLimit, typeLabel, typeShort, validateEmployee } from './employeeConfig';

describe('employee screen helpers', () => {
    it('joins a name and tidies spaces', () => {
        expect(joinName(' Maria  Cristina ', 'Reyes', 'Santos')).toBe('Maria Cristina Reyes Santos');
        expect(joinName('Jose', '', 'Cruz')).toBe('Jose Cruz');
        expect(joinName('Jose', null, 'Cruz')).toBe('Jose Cruz');
    });

    it('names the two employee types the way the client says them', () => {
        expect(typeLabel('DIRECT')).toBe('TSC (direct)');
        expect(typeShort('SUBCONTRACTOR')).toBe('SUB');
        expect(typeLabel('OTHER')).toBe('OTHER');
    });

    it('checks the form: names are required and the type only matters on create', () => {
        expect(validateEmployee({ first_name: ' ', middle_name: '', last_name: '', employee_type: '' }, false)).toEqual({
            first_name: 'Enter a first name.',
            last_name: 'Enter a last name.',
            employee_type: 'Choose TSC (direct) or SUB (subcontractor).',
        });
        expect(validateEmployee({ first_name: 'A', middle_name: '', last_name: 'B', employee_type: '' }, true)).toEqual({});
    });

    it('sends the type only when creating and never a code', () => {
        const values = { first_name: ' Ana ', middle_name: ' ', last_name: 'Reyes', employee_type: 'DIRECT' };
        expect(employeeBody(values, false)).toEqual({ employee_type: 'DIRECT', first_name: 'Ana', middle_name: null, last_name: 'Reyes' });
        expect(employeeBody(values, true)).toEqual({ first_name: 'Ana', middle_name: null, last_name: 'Reyes' });
        expect(Object.keys(employeeBody(values, false))).not.toContain('employee_code');
    });

    it('normalises a typed credit limit to two decimals and refuses anything that is not an amount', () => {
        expect(normalizeLimit('1500')).toBe('1500.00');
        expect(normalizeLimit('₱1,500.5')).toBe('1500.50');
        expect(normalizeLimit(' 0 ')).toBe('0.00');
        expect(normalizeLimit('007')).toBe('7.00');
        expect(normalizeLimit('-5')).toBeNull();
        expect(normalizeLimit('10.999')).toBeNull();
        expect(normalizeLimit('abc')).toBeNull();
        expect(normalizeLimit('')).toBeNull();
    });

    it('shows a limit as pesos with thousands separators', () => {
        expect(formatLimit('1500.00')).toBe('₱1,500.00');
        expect(formatLimit('1000000.5')).toBe('₱1,000,000.50');
        expect(formatLimit('0.00')).toBe('₱0.00');
    });

    it('builds the list query and leaves blank filters out', () => {
        expect(employeeQuery({ search: ' ana ', type: 'DIRECT', status: 'inactive', page: 2, perPage: 25 })).toBe('per_page=25&page=2&search=ana&employee_type=DIRECT&active=false');
        expect(employeeQuery({ status: 'active' })).toBe('active=true');
        expect(employeeQuery()).toBe('');
    });

    it('splits the ID cards into printed pages', () => {
        const cards = Array.from({ length: 19 }, (_, index) => ({ id: String(index) }));
        expect(chunkCards(cards, 8).map((page) => page.length)).toEqual([8, 8, 3]);
        expect(chunkCards([], 8)).toEqual([]);
    });

    it('summarises an import in one line', () => {
        expect(importHeadline({ created: 3, skipped: 0, failed: 0 }, false)).toBe('3 will be created');
        expect(importHeadline({ created: 3, skipped: 2, failed: 1 }, true)).toBe('3 created, 2 skipped as already enrolled, 1 with problems');
    });
});

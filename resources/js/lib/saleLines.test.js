import { describe, expect, it } from 'vitest';
import { saleLineName } from './saleLines';

const items = [
    { line_number: 1, product_name_snapshot: 'Standard Meal - Beef nilaga', parent_line_number: null },
    { line_number: 2, product_name_snapshot: 'Whole rice', parent_line_number: 1 },
    { line_number: 3, product_name_snapshot: 'Standard Meal - Pork adobo', parent_line_number: null },
    { line_number: 4, product_name_snapshot: 'Whole rice', parent_line_number: 3 },
    { line_number: 5, product_name_snapshot: 'Coke 330ml' },
];

describe('saleLineName', () => {
    it('labels each add-on with the meal line it belongs to', () => {
        expect(saleLineName(items[1], items)).toBe('Whole rice (for Standard Meal - Beef nilaga)');
        expect(saleLineName(items[3], items)).toBe('Whole rice (for Standard Meal - Pork adobo)');
    });

    it('leaves meal lines and product lines unchanged, with or without the field', () => {
        expect(saleLineName(items[0], items)).toBe('Standard Meal - Beef nilaga');
        expect(saleLineName(items[4], items)).toBe('Coke 330ml');
    });

    it('shows the plain name when the meal line is not in the list', () => {
        expect(saleLineName({ line_number: 9, product_name_snapshot: 'Whole rice', parent_line_number: 7 }, items)).toBe('Whole rice');
    });
});

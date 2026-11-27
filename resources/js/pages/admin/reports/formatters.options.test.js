import { describe, expect, it } from 'vitest';
import { deriveOptions } from './formatters';

const filter = { optionValue: 'product_id', optionCode: (r) => r.sku, optionName: (r) => r.product_name };

describe('deriveOptions', () => {
    it('gives one choice per id, naming every name it appears under', () => {
        const rows = [
            { product_id: 'm', sku: 'MEAL', product_name: 'Regular Meal' },
            { product_id: 'r', sku: 'RICE', product_name: 'Whole rice' },
            { product_id: 'm', sku: 'MEAL', product_name: 'Standard Meal' },
            { product_id: 'm', sku: 'MEAL', product_name: 'Regular Meal' },
            { product_id: null, sku: null, product_name: 'nobody' },
        ];

        expect(deriveOptions(rows, filter)).toEqual([
            { value: 'm', code: 'MEAL', name: 'Regular Meal / Standard Meal' },
            { value: 'r', code: 'RICE', name: 'Whole rice' },
        ]);
    });
});

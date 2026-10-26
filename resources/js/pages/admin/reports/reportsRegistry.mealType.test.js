import { describe, expect, it } from 'vitest';
import { REPORTS, mealTypeSubtotals } from './reportsRegistry';

const report = REPORTS.find((entry) => entry.slug === 'sales-by-meal-type');

describe('Sales by Meal Type and Dish report', () => {
    it('is registered under Sales with the server columns in the CSV order', () => {
        expect(report.category).toBe('sales');
        expect(report.columns.map((column) => column.key)).toEqual(['meal_type', 'item', 'item_type', 'quantity_sold', 'quantity_returned', 'quantity_net', 'sold_amount', 'returned_amount', 'net_amount']);
        expect(report.summary.map((card) => card.key)).toEqual(['sales', 'refunds', 'net_sales', 'voids']);
        expect(report.exportPrefix).toBe('sales_by_meal_type');
        expect(report.filters).toEqual([{ type: 'date_range', defaultPreset: 'today' }]);
    });

    it('shows a net subtotal card per meal type, summed exactly from the item rows', () => {
        const rows = [
            { meal_type: 'Standard Meal', net_amount: '68.10' },
            { meal_type: 'Standard Meal', net_amount: '0.20' },
            { meal_type: 'Other items', net_amount: '100.00' },
        ];

        expect(mealTypeSubtotals(rows)).toEqual([
            { label: 'Standard Meal', value: '₱68.30', sub: 'net of refunds' },
            { label: 'Other items', value: '₱100.00', sub: 'net of refunds' },
        ]);
        expect(report.derivedSummary([])).toEqual([]);
    });
});

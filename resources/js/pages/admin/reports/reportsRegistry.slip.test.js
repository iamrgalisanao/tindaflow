import { describe, expect, it } from 'vitest';
import { reportBySlug, withDocumentColumn } from './reportsRegistry';

const NAMING_A_DOCUMENT = ['sales-by-date-range', 'discounts', 'voids', 'refunds'];

describe('withDocumentColumn', () => {
    it('leaves a report as it is for a store that has only invoices', () => {
        for (const slug of NAMING_A_DOCUMENT) {
            const report = reportBySlug(slug);
            const rows = [{ invoice_number: '000123' }];

            const shown = withDocumentColumn(report, rows);

            expect(shown.report).toBe(report);
            expect(shown.rows).toBe(rows);
            expect(report.columns.find((column) => column.key === 'invoice_number').label).toBe('Invoice #');
        }
    });

    it('shows whichever number each sale has, under a heading that says either, in the same place', () => {
        for (const slug of NAMING_A_DOCUMENT) {
            const report = reportBySlug(slug);
            const place = report.columns.findIndex((column) => column.key === 'invoice_number');

            const shown = withDocumentColumn(report, [
                { invoice_number: '000123', slip_number: null },
                { invoice_number: null, slip_number: 'T-01-000007' },
            ]);

            expect(shown.report.columns[place]).toMatchObject({ key: 'document_number', label: 'Invoice / slip #', format: 'mono_id' });
            expect(shown.report.columns).toHaveLength(report.columns.length);
            expect(shown.rows.map((row) => row.document_number)).toEqual(['000123', 'T-01-000007']);
        }
    });

    it('does not change the shared report definition', () => {
        const report = reportBySlug('voids');

        withDocumentColumn(report, [{ invoice_number: null, slip_number: 'T-01-000001' }]);

        expect(report.columns.some((column) => column.key === 'invoice_number')).toBe(true);
    });

    it('has nothing to change with no rows or no report', () => {
        const report = reportBySlug('voids');

        expect(withDocumentColumn(report, []).report).toBe(report);
        expect(withDocumentColumn(null, []).report).toBeNull();
    });
});

import { describe, expect, it } from 'vitest';
import { documentEndpoints, documentLabel, documentReference, documentTitle, isSlipSale } from './saleDocument';

const invoiceSale = { document_mode: 'INVOICE', document_reference: '000123', invoice_number: '000123', invoice: { id: 'inv-1' }, slip: null };
const slipSale = { document_mode: 'SLIP', document_reference: 'T-01-000004', invoice_number: null, invoice: null, slip: { id: 'slip-1', slip_number: 'T-01-000004' } };

describe('saleDocument', () => {
    it('names an invoice sale by its invoice number', () => {
        expect(isSlipSale(invoiceSale)).toBe(false);
        expect(documentLabel(invoiceSale)).toBe('Invoice');
        expect(documentReference(invoiceSale)).toBe('000123');
        expect(documentTitle(invoiceSale)).toBe('Invoice 000123');
    });

    it('names a slip sale by its slip number and never calls it an invoice', () => {
        expect(isSlipSale(slipSale)).toBe(true);
        expect(documentTitle(slipSale)).toBe('Slip T-01-000004');
        expect(documentTitle(slipSale).toLowerCase()).not.toContain('invoice');
    });

    it('falls back to the invoice fields for an answer without the document fields', () => {
        expect(documentReference({ invoice_number: '000009' })).toBe('000009');
        expect(documentTitle({})).toBe('Invoice —');
    });

    it('points at the endpoints of the document the sale actually has', () => {
        expect(documentEndpoints(invoiceSale)).toEqual({ id: 'inv-1', original: '/api/v1/invoices/inv-1', reprint: '/api/v1/invoices/inv-1/reprints', key: 'invoice' });
        expect(documentEndpoints(slipSale)).toEqual({ id: 'slip-1', original: '/api/v1/sale-slips/slip-1', reprint: '/api/v1/sale-slips/slip-1/reprints', key: 'slip' });
        expect(documentEndpoints({ document_mode: 'SLIP', slip: null })).toBeNull();
        expect(documentEndpoints({ document_mode: 'INVOICE', invoice: null })).toBeNull();
    });
});

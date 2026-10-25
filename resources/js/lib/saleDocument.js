/**
 * ADR-016: a sale is documented by an invoice or, in a store on slip mode, by a slip. These helpers keep every screen from assuming
 * an invoice: they read the server's `document_mode` and `document_reference` (the invoice number, or the slip number) and fall back
 * to the invoice fields for an answer from before slip mode existed.
 */
export const isSlipSale = (sale) => sale?.document_mode === 'SLIP';

/** "Invoice" or "Slip". */
export const documentLabel = (sale) => (isSlipSale(sale) ? 'Slip' : 'Invoice');

/** The number printed on the sale's document, or null when it has none. */
export const documentReference = (sale) => sale?.document_reference ?? sale?.invoice_number ?? null;

/** "Invoice 000123" / "Slip T-01-000004" -- "—" stands in for a sale with no number. */
export const documentTitle = (sale) => `${documentLabel(sale)} ${documentReference(sale) ?? '—'}`;

/** The id of the sale's printable document and the endpoints that print it. */
export function documentEndpoints(sale) {
    if (isSlipSale(sale)) {
        return sale.slip ? { id: sale.slip.id, original: `/api/v1/sale-slips/${sale.slip.id}`, reprint: `/api/v1/sale-slips/${sale.slip.id}/reprints`, key: 'slip' } : null;
    }

    return sale?.invoice ? { id: sale.invoice.id, original: `/api/v1/invoices/${sale.invoice.id}`, reprint: `/api/v1/invoices/${sale.invoice.id}/reprints`, key: 'invoice' } : null;
}

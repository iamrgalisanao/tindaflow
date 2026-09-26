export default {
    slug: 'sales-history',
    category: 'manage',
    title: 'Look up sales and reprint an invoice',
    summary: 'Search every sale in the store, open one to see its items and payments, and print a marked copy of its invoice.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 4,
    before: ['You are signed in as a **Manager** or **Admin**. Cashiers use **Lookup** at the till for their own sales.'],
    steps: [
        {
            title: 'Search the list',
            body: 'Open **Sales**, then **Sales history**. Every sale in the store is listed with the newest first. Type an **Invoice no.** or **Transaction no.**, or filter by **Status**, **Paid with**, dates and order.',
            figure: {
                shot: 'sales-list',
                alt: 'The Sales history list with search boxes, filters and a table of sales.',
                marks: [
                    { type: 'type', target: 'invoice', label: 'Invoice number', detail: 'Type an invoice number to find one sale:', text: '000001' },
                    { type: 'look', target: 'table', label: 'The sales', detail: 'Status shows COMPLETED, VOIDED, PARTIALLY REFUNDED or REFUNDED.' },
                ],
            },
        },
        {
            title: 'Open a sale',
            body: 'Click a row. The detail page shows the items with their prices, discounts and tax, the totals, how it was paid and any refunds. **Invoice** opens the invoice. **Refund** and **Void sale** (managers and admins) act on the sale at once, without needing another approval.',
            figure: {
                shot: 'sales-detail',
                alt: 'A sale’s detail page with items, totals, payment and a refund.',
                marks: [
                    { type: 'look', target: 'items', label: 'What was sold', detail: 'Each line with quantity, price, discount and tax.' },
                    { type: 'look', target: 'payment', label: 'How it was paid', detail: 'Payment method, amount tendered and change.' },
                    { type: 'click', target: 'invoice', label: 'Click Invoice', detail: 'Click Invoice to see or print it.' },
                    { type: 'click', target: 'back', label: 'Back to the list', detail: 'Click Sales history to go back.' },
                ],
            },
        },
        {
            title: 'Print a copy of the invoice',
            body: 'In the **Invoice** panel, click **Print a copy**. A copy is marked **REPRINT — COPY** and recorded in the audit log. The original invoice, its number and the sale are never changed.',
            figure: {
                shot: 'sales-invoice-panel',
                alt: 'The Invoice panel explaining that a copy is marked and recorded, with a Print a copy button.',
                marks: [{ type: 'click', target: 'reprint', label: 'Click Print a copy', detail: 'Click Print a copy.' }],
            },
            warning: 'Printing copies for people who are not the customer is not what this is for. Every copy is recorded with your name.',
        },
    ],
    done: 'You can find any sale and reprint its invoice.',
    next: ['approve-void-refund', 'reports'],
};

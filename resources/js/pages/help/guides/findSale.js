export default {
    slug: 'find-sale',
    category: 'till',
    title: 'Find an earlier sale and print a copy',
    summary: 'Look up a transaction from the till, see what was sold and how it was paid, and print a marked copy of the receipt.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 3,
    before: ['You are signed in and on the till.'],
    steps: [
        {
            title: 'Open Lookup',
            body: 'Click **Lookup** at the top. Today’s transactions are listed with the newest first. Each row shows the transaction number, invoice number, time, status and total. Type a transaction or invoice number in the search box to find a particular one.',
            figure: {
                shot: 'till-lookup',
                alt: 'The Lookup screen with a search box and a list of recent transactions.',
                marks: [
                    { type: 'click', target: 'tabs', label: 'Click Lookup', detail: 'Click Lookup in the top buttons.' },
                    { type: 'type', target: 'search', label: 'Search', detail: 'Type an invoice or transaction number to find one:', text: '000003' },
                    { type: 'look', target: 'list', label: 'Recent sales', detail: 'A cashier sees their own sales. A manager can look up everyone’s.' },
                ],
            },
        },
        {
            title: 'Open a transaction',
            body: 'Click a row. The right side shows the items, discounts, VAT, total, how it was paid and the change given. The buttons underneath let you print a copy or ask for a void or refund.',
            figure: {
                shot: 'till-lookup-detail',
                alt: 'A transaction opened on the right with Receipt, Request void and Request refund buttons.',
                marks: [
                    { type: 'click', target: 'row', label: 'Click a sale', detail: 'Click the sale you want.' },
                    { type: 'click', target: 'receipt', label: 'Receipt', detail: 'Click Receipt to print a copy for the customer.' },
                    { type: 'avoid', target: 'void', label: 'Not just to look', detail: 'Don’t click Request void or Request refund just to look. They start a request for a manager.' },
                ],
            },
            note: 'Printing from here records a copy marked **REPRINT — COPY**. The unmarked original is printed only once, at the moment of the sale.',
        },
    ],
    done: 'To cancel a sale or give money back, follow the void and refund guide.',
    next: ['request-void-refund'],
};

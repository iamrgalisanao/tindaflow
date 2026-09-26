export default {
    slug: 'records',
    category: 'manage',
    title: 'Shifts, audit log and electronic journal',
    summary: 'Check how each drawer balanced, who did what and when, and the permanent snapshot of every invoice, void and reading.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 5,
    before: ['You are signed in as a **Manager** or **Admin**. Some records need extra permissions, so a manager may not see all three.'],
    steps: [
        {
            title: 'Check the shifts',
            body: 'Open **Records**, then **Shifts**. Each row is a cashier’s time at a till: the **opening** cash, what the drawer was **expected** to hold, what they **declared** and the **variance**. **Short** means less cash than expected.',
            figure: {
                shot: 'records-shifts',
                alt: 'The Shifts list with opening, expected and declared cash and the variance.',
                marks: [{ type: 'look', target: 'table', label: 'Drawer results', detail: 'Balanced, Short or Over for every shift.' }],
            },
            tip: 'A shift that is often short or over deserves a friendly conversation and a look at its sales and cash movements.',
        },
        {
            title: 'See who did what in the audit log',
            body: 'Open **Records**, then **Audit log**. Each entry says what happened, who did it, on which terminal and why. Choose **What happened** to filter, for example **Discount applied** or **Sale voided**, and set dates with **From** and **To**. Entries can never be changed or removed.',
            figure: {
                shot: 'records-audit',
                alt: 'The Audit log with a filter for what happened and a list of entries.',
                marks: [
                    { type: 'click', target: 'filter', label: 'Filter', detail: 'Choose the kind of event to see.' },
                    { type: 'look', target: 'rows', label: 'Entries', detail: 'Newest first, with the reason each person gave.' },
                ],
            },
        },
        {
            title: 'Use the electronic journal',
            body: 'Open **Records**, then **Electronic journal**. It keeps a read-only snapshot of every invoice, void, refund, reading, cash movement and stock change, taken at the moment it happened. Choose a **Type** to narrow it and click **Export CSV** for a copy.',
            figure: {
                shot: 'records-journal',
                alt: 'The Electronic journal with a type filter and an Export CSV button.',
                marks: [
                    { type: 'click', target: 'type', label: 'Type', detail: 'Choose Invoice, Void, Refund, X-Reading and so on.' },
                    { type: 'click', target: 'export', label: 'Click Export CSV', detail: 'Click Export CSV for a copy.' },
                ],
            },
        },
    ],
    done: 'Everything that happens in the store leaves a record here that nobody can edit.',
    next: ['reports'],
};

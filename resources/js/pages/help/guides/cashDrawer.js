export default {
    slug: 'cash-drawer',
    category: 'till',
    title: 'Cash in, cash out and X-readings',
    summary: 'Record money put into or taken out of the drawer during your shift, and take a mid-shift reading.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 5,
    before: ['Your shift is open.'],
    steps: [
        {
            title: 'Open the Shift tab',
            body: 'Click **Shift** at the top. The **Cash drawer** box on the left is where you record money moving in or out. **This shift** shows your opening cash. **X-reading** is at the bottom.',
            figure: {
                shot: 'till-shift-tab',
                alt: 'The Shift screen with the cash drawer form, the shift summary and the X-reading box.',
                marks: [
                    { type: 'click', target: 'tabs', label: 'Click Shift', detail: 'Click Shift at the top.' },
                    { type: 'look', target: 'drawer', label: 'Cash drawer', detail: 'Record money put into the drawer or taken out of it, with the reason.' },
                    { type: 'look', target: 'summary', label: 'This shift', detail: 'Your opening cash for this shift.' },
                ],
            },
        },
        {
            title: 'Record cash in',
            body: 'Choose **Cash in (put into the drawer)**, type the **Amount** and a **Reason**, then click **Record**. Use this when the owner adds a change fund, for example.',
            figure: {
                shot: 'till-cash-in',
                alt: 'The cash drawer form with cash in chosen, an amount and a reason.',
                marks: [
                    { type: 'click', target: 'type', label: 'Cash in or out', detail: 'Choose Cash in or Cash out.' },
                    { type: 'type', target: 'amount', label: 'Amount', detail: 'Amount in pesos:', text: '200.00' },
                    { type: 'type', target: 'reason', label: 'Reason', detail: 'Reason:', text: 'Change fund from the owner' },
                    { type: 'click', target: 'record', label: 'Click Record', detail: 'Click Record.' },
                ],
            },
            warning: 'Cash out works the same way, for example paying a supplier from the drawer. A large cash-out needs a manager’s permission. Always write a clear reason.',
        },
        {
            title: 'Check that it was recorded',
            body: 'A green line says **Recorded cash in** with the amount. It counts in the drawer figures at the end of the shift.',
            figure: {
                shot: 'till-cash-in-done',
                alt: 'A confirmation line saying the cash in was recorded.',
                marks: [{ type: 'look', target: 'notice', label: 'Recorded', detail: 'The system now expects that money in the drawer.' }],
            },
        },
        {
            title: 'Take an X-reading when you need to',
            body: 'Click **Take X-reading** to see how the shift stands so far: number of sales, non-cash sales by method, and more. An X-reading **changes nothing**: it does not close the shift and you can take as many as you like. As a cashier, the cash figures are hidden so your final count stays honest.',
            figure: {
                shot: 'till-xreading',
                alt: 'An X-reading showing transactions and payment totals, with the cash figures withheld.',
                marks: [
                    { type: 'click', target: 'take', label: 'Click Take X-reading', detail: 'Click Take X-reading.' },
                    { type: 'look', target: 'figures', label: 'The reading', detail: 'Interim figures for the shift so far. Cash amounts show WITHHELD for cashiers.' },
                ],
            },
        },
    ],
    done: 'When your shift ends, count the drawer and close it.',
    next: ['close-shift'],
};

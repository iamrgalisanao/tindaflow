export default {
    slug: 'close-business-day',
    category: 'manage',
    title: 'Close the business day',
    summary: 'At the end of the day, a manager closes the business day at the till. This produces the day’s Z-reading, which is then fixed for good.',
    roles: ['MANAGER', 'ADMIN'],
    startHere: ['MANAGER'],
    minutes: 6,
    before: [
        'You are signed in as a **Manager** or **Admin** at the till’s **enrolled** browser. Cashiers cannot close the day.',
        'Every cashier has **closed their shift**. If one is still open, close it for them (see the note in step 1).',
        'All void and refund requests for the day are decided. A void only works while the business day is open.',
    ],
    steps: [
        {
            title: 'Close the last shift',
            body: 'The **Close business day** button appears on the summary after **you** close a shift at the till. Open the till, go to **Shift** and click **Close shift**. If another cashier left a shift open, the till offers **Count the drawer and close it**. Type the counted cash and click **Close shift**.',
            figure: {
                shot: 'till-close-shift',
                alt: 'The Close shift form asking for the counted cash.',
                marks: [
                    { type: 'type', target: 'counted', label: 'Counted cash', detail: 'Counted cash for the drawer:', text: '320.00' },
                    { type: 'click', target: 'close', label: 'Click Close shift', detail: 'Click Close shift.' },
                ],
            },
            note: 'If no shift is open when you arrive, start one with the drawer’s cash, then close it straight away. That is how the summary with the day button is reached.',
        },
        {
            title: 'Click Close business day',
            body: 'The summary shows the shift’s expected and counted cash and its variance. Only managers and admins also see **Close business day**. Click it.',
            figure: {
                shot: 'day-shift-closed',
                alt: 'The shift closed summary with a Close business day button.',
                marks: [
                    { type: 'look', target: 'variance', label: 'Check the variance', detail: 'A variance of zero means the drawer balanced.' },
                    { type: 'click', target: 'day', label: 'Click Close business day', detail: 'Click Close business day.' },
                ],
            },
            warning: 'The day cannot be reopened. If a shift is still open the system refuses to close the day and tells you why.',
        },
        {
            title: 'Read the Z-reading',
            body: 'The **Business day closed** screen shows the **Z-Reading number** and the day’s **Gross sales**, **VAT**, **Void total** and **Refund total**. These figures are fixed for good.',
            figure: {
                shot: 'day-closed',
                alt: 'The Business day closed screen with the Z-reading number and totals.',
                marks: [
                    { type: 'look', target: 'z', label: 'Z-Reading number', detail: 'Each closed day gets the next number.' },
                    { type: 'look', target: 'gross', label: 'Day totals', detail: 'Gross sales, VAT, void total and refund total for the day.' },
                ],
            },
        },
        {
            title: 'Find it again later',
            body: 'Open **Records**, then **Fiscal days**. Each business day is listed. Click a closed day to see its shifts and its Z-reading.',
            figure: {
                shot: 'records-fiscal-days',
                alt: 'The Fiscal days list.',
                marks: [{ type: 'look', target: 'table', label: 'Business days', detail: 'Click a closed day to open its Z-reading.' }],
            },
        },
        {
            title: 'Open a day’s Z-reading',
            body: 'The day’s page lists every **shift** in it with who worked and how the drawer balanced, then the **Z-reading**, fixed when the day was closed and never recalculated.',
            figure: {
                shot: 'records-z-reading',
                alt: 'A closed business day with its shifts and its Z-reading.',
                marks: [{ type: 'look', target: 'z', label: 'The Z-reading', detail: 'The end-of-day figures, fixed when the day was closed.' }],
            },
        },
    ],
    done: 'The day is closed and its figures are fixed. Tomorrow’s first shift opens a new business day.',
    next: ['records', 'reports'],
};

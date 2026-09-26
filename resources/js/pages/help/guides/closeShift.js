export default {
    slug: 'close-shift',
    category: 'till',
    title: 'Close your shift',
    summary: 'Count the drawer, tell the system the number, and see how close you were.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    startHere: ['CASHIER'],
    minutes: 4,
    before: ['You have finished selling. There is no sale still in the cart.', 'You have counted **all the cash** in the drawer, notes and coins.'],
    steps: [
        {
            title: 'Click Close shift',
            body: 'Click **Shift** at the top, then **Close shift** under **This shift**.',
            figure: {
                shot: 'till-shift-tab',
                alt: 'The Shift screen with a Close shift button under This shift.',
                marks: [
                    { type: 'click', target: 'tabs', label: 'Click Shift', detail: 'Click Shift.' },
                    { type: 'click', target: 'close', label: 'Click Close shift', detail: 'Click Close shift.' },
                ],
            },
        },
        {
            title: 'Type the cash you counted',
            body: 'Type the cash in the drawer into **Counted cash** and click **Close shift**. The system does not show you what it expects until after you submit. That keeps the count honest.',
            figure: {
                shot: 'till-close-shift',
                alt: 'The Close shift form with a Counted cash box.',
                marks: [
                    { type: 'look', target: 'note', label: 'Blind count', detail: 'The expected amount and any variance are never shown before you submit.' },
                    { type: 'type', target: 'counted', label: 'Counted cash', detail: 'Counted cash:', text: '953.00' },
                    { type: 'click', target: 'close', label: 'Click Close shift', detail: 'Click Close shift.' },
                ],
            },
            donts: ['Don’t guess or copy a number. Count the money. A wrong count hides a real problem.'],
        },
        {
            title: 'Read the result',
            body: 'The summary shows **Expected cash**, **Counted cash** and the **Variance**. A red negative variance means there is less cash than expected. A manager can review the shift later. Click **Back to dashboard**.',
            figure: {
                shot: 'till-shift-closed',
                alt: 'The shift closed summary with expected cash, counted cash and variance.',
                marks: [
                    { type: 'look', target: 'expected', label: 'Expected', detail: 'What the system worked out should be in the drawer.' },
                    { type: 'look', target: 'variance', label: 'Variance', detail: 'Counted minus expected. Zero means balanced.' },
                    { type: 'click', target: 'back', label: 'Back to dashboard', detail: 'Click Back to dashboard, then sign out.' },
                ],
            },
            note: 'The figures in the picture are examples from a sample store. Yours will show your own shift.',
            tip: 'Sign out after closing so nobody sells under your name.',
        },
    ],
    done: 'Your shift is closed. A manager will close the business day when every shift is done.',
    next: ['close-business-day'],
};

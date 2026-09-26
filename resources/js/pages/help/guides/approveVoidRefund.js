export default {
    slug: 'approve-void-refund',
    category: 'manage',
    title: 'Approve or reject a void or refund',
    summary: 'A cashier has asked to cancel a sale or return items. Read the reason, then approve it or turn it down.',
    roles: ['MANAGER', 'ADMIN'],
    startHere: ['MANAGER'],
    minutes: 5,
    before: [
        'You are signed in as a **Manager** or **Admin**, on the till’s **enrolled** browser.',
        'You have an **open shift** on this till. Approving happens at this terminal, on your own open shift, not the cashier’s.',
        'The Dashboard tells you how many requests are waiting under **Needs attention**.',
    ],
    steps: [
        {
            title: 'Open Approvals',
            body: 'Open **Sales**, then **Approvals**. Requests are grouped by **Voids** and **Refunds**. **Waiting** shows only what still needs a decision; **All** shows history. Each row has the cashier’s reason. Click **Review** on the one you want.',
            figure: {
                shot: 'approvals-list',
                alt: 'The Approvals page with a waiting void request and a Review button.',
                marks: [
                    { type: 'look', target: 'row', label: 'A request', detail: 'The reason the cashier gave, when it was asked, and its status.' },
                    { type: 'click', target: 'review', label: 'Click Review', detail: 'Click Review.' },
                ],
            },
            note: 'Nothing changes on the sale, in stock or in the drawer until you approve.',
        },
        {
            title: 'Read the void request and decide',
            body: 'The panel shows the sale, its total, who asked and why. **Approve void** cancels the whole sale and puts every item back in stock. It only works while the sale’s business day is still open. Use **Reject…** to turn it down.',
            figure: {
                shot: 'approvals-review-void',
                alt: 'The void request panel with the reason and Reject and Approve void buttons.',
                marks: [
                    { type: 'look', target: 'reason', label: 'Their reason', detail: 'Read the reason. Does it make sense for this sale?' },
                    { type: 'click', target: 'approve', label: 'Approve void', detail: 'Click Approve void to cancel the sale.' },
                    { type: 'avoid', target: 'reject', label: 'Only if it is not right', detail: 'Use Reject… when the request is wrong. You must say why.' },
                ],
            },
            warning: 'Approving a void cannot be undone. Check the invoice number and the total first.',
        },
        {
            title: 'See the confirmation',
            body: 'A green line says **Void approved** and the sale is voided with its items back in stock. It disappears from **Waiting**.',
            figure: {
                shot: 'approvals-void-done',
                alt: 'A confirmation saying the void was approved.',
                marks: [{ type: 'look', target: 'notice', label: 'Approved', detail: 'The sale is now VOIDED. It no longer counts in sales totals.' }],
            },
        },
        {
            title: 'Switch to Refunds',
            body: 'Click **Refunds** at the top of the list to see refund requests, then **Review**.',
            figure: {
                shot: 'approvals-refunds-tab',
                alt: 'The Approvals page switched to Refunds.',
                marks: [
                    { type: 'click', target: 'show', label: 'Click Refunds', detail: 'Click Refunds.' },
                    { type: 'click', target: 'review', label: 'Click Review', detail: 'Click Review on the request.' },
                ],
            },
        },
        {
            title: 'Check the refund lines and money',
            body: 'The panel lists the **items being returned**, what happens to each, and the **money returned** and how. **Approve refund** pays it back: cash comes out of the drawer, other methods do not.',
            figure: {
                shot: 'approvals-review-refund',
                alt: 'The refund request panel with the items being returned and the money returned.',
                marks: [
                    { type: 'click', target: 'approve', label: 'Approve refund', detail: 'Click Approve refund to complete it.' },
                    { type: 'avoid', target: 'reject', label: 'Reject if not right', detail: 'Reject… turns the request down and needs a reason.' },
                ],
            },
        },
        {
            title: 'To turn a request down, say why',
            body: 'If you click **Reject…**, type **Why is this being turned down?** and click **Reject request**. The cashier can see your reason. Click **Back** if you changed your mind.',
            figure: {
                shot: 'approvals-reject-form',
                alt: 'The reject form asking why the request is being turned down.',
                marks: [
                    { type: 'type', target: 'reason', label: 'Why', detail: 'Why is this being turned down?', text: 'The receipt shows this item was not part of the sale.' },
                    { type: 'click', target: 'reject', label: 'Click Reject request', detail: 'Click Reject request.' },
                    { type: 'click', target: 'back', label: 'Back', detail: 'Or click Back to return without deciding.' },
                ],
            },
        },
    ],
    done: 'Every decision is recorded with your name in the audit log.',
    next: ['sales-history', 'records'],
};

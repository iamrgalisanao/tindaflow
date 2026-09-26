export default {
    slug: 'request-void-refund',
    category: 'till',
    title: 'Cancel a sale or give a refund',
    summary: 'A cashier asks for a void (cancel the whole sale) or a refund (return some items and money). A manager decides.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['You have found the sale in **Lookup** (see “Find an earlier sale”).', 'Void or refund? A **void** cancels the whole sale and puts everything back in stock. A **refund** returns chosen items and money. Use a refund when only some items come back.'],
    steps: [
        {
            title: 'Choose Request void or Request refund',
            body: 'With the sale open in **Lookup**, click **Request void** to cancel the whole sale, or **Request refund** to return some items. Nothing changes yet: a manager must approve.',
            figure: {
                shot: 'till-lookup-detail',
                alt: 'The sale detail with Request void and Request refund buttons.',
                marks: [
                    { type: 'click', target: 'void', label: 'Whole sale', detail: 'Request void cancels the whole sale.' },
                    { type: 'click', target: 'refund', label: 'Some items', detail: 'Request refund returns some of the items.' },
                ],
            },
            note: 'For a manager or admin the buttons read **Void sale** and **Refund**, and they take effect at once.',
        },
        {
            title: 'For a void, write the reason',
            body: 'The panel says what will be cancelled. Type a **Reason** (it is kept with the record and shown to the manager) and click **Request void**.',
            figure: {
                shot: 'till-void-form',
                alt: 'The Void sale panel with a reason box and a Request void button.',
                marks: [
                    { type: 'look', target: 'info', label: 'What it does', detail: 'A void cancels the full sale. It only works while the sale’s business day is still open; after that, use a refund.' },
                    { type: 'type', target: 'reason', label: 'Reason', detail: 'Reason:', text: 'Customer decided not to buy; items returned right away.' },
                    { type: 'click', target: 'request', label: 'Click Request void', detail: 'Click Request void.' },
                ],
            },
            donts: ['Don’t write “mistake” with no detail. The manager reads your reason before deciding.'],
        },
        {
            title: 'See that the request was sent',
            body: 'A line on the sale says **Void requested** with your reason. The sale still shows as **COMPLETED** until a manager approves it under **Approvals**.',
            figure: {
                shot: 'till-void-requested',
                alt: 'A notice saying the void was requested and that a manager decides it under Approvals.',
                marks: [{ type: 'look', target: 'notice', label: 'Requested', detail: 'The request is waiting for a manager.' }],
            },
        },
        {
            title: 'For a refund, choose the items and the money',
            body: 'For each item coming back, type the **Quantity returned** and choose **What happens to it** (**Return to stock**, **Damaged**, **Expired** or **Disposed**). Then choose how the **Money returned** is paid back and the amount. Write the **Reason** and click **Request refund**.',
            figure: {
                shot: 'till-refund-form',
                alt: 'The Refund panel with a returned quantity, what happens to it, the money returned, a reason and a Request refund button.',
                marks: [
                    { type: 'type', target: 'qty', label: 'Quantity returned', detail: 'Quantity returned for that item:', text: '1' },
                    { type: 'click', target: 'kind', label: 'What happens to it', detail: 'Choose what happens to the item: back to stock, damaged, expired or disposed.' },
                    { type: 'click', target: 'money', label: 'How the money goes back', detail: 'Choose how the customer is paid back.' },
                    { type: 'type', target: 'amount', label: 'Amount', detail: 'The amount to return:', text: '20.00' },
                    { type: 'type', target: 'reason', label: 'Reason', detail: 'Reason:', text: 'Pack was already open when the customer got home.' },
                    { type: 'click', target: 'request', label: 'Click Request refund', detail: 'Click Request refund.' },
                ],
            },
            note: 'Cash refunds come out of the drawer. Other methods do not. You can split the money across more than one method.',
        },
    ],
    done: 'The manager will review it under Approvals. Nothing moves in stock or in the drawer until then.',
    next: ['approve-void-refund'],
};

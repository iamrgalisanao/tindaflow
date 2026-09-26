export default {
    slug: 'pay-other',
    category: 'till',
    title: 'Take GCash, Maya, card or a split payment',
    summary: 'Charge a non-cash method, or let a customer pay part in cash and part another way.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 5,
    before: ['The cart is ready and you have clicked **Charge**, so the **Payment** screen is showing.'],
    steps: [
        {
            title: 'Choose the payment method',
            body: 'The row of buttons above the amount box lists **Cash**, **GCash**, **Maya**, **Card** and **Other**. Tap the one the customer is using. For a single non-cash payment the full total is filled in for you, so there is no change to give.',
            figure: {
                shot: 'till-tender',
                alt: 'The Payment screen with five payment method buttons.',
                marks: [{ type: 'click', target: 'methods', label: 'Choose the method', detail: 'Tap Cash, GCash, Maya, Card or Other.' }],
            },
            tip: 'Confirm that the payment has really arrived in your GCash or Maya app, or that the card machine approved it, before you click Complete sale.',
        },
        {
            title: 'For a mixed payment, set the first part',
            body: 'If the customer pays in two ways, tap the first method (for example **GCash**), then change the amount to what they are paying that way, for example `40.00`.',
            figure: {
                shot: 'till-split-1',
                alt: 'The Payment screen with GCash chosen, an amount of 40 and a Split payment link.',
                marks: [
                    { type: 'click', target: 'gcash', label: 'Choose GCash', detail: 'Tap GCash.' },
                    { type: 'type', target: 'amount', label: 'Amount', detail: 'Type the part paid this way:', text: '40.00' },
                    { type: 'click', target: 'split', label: 'Click Split payment', detail: 'Click Split payment to add a second row.' },
                ],
            },
        },
        {
            title: 'Add the second payment',
            body: 'A second row appears with the **remaining amount** already filled in and **Cash** chosen. Change the method or the amount if you need to. Tap a row to make it the one you are editing. Tap **×** on a row to remove it.',
            figure: {
                shot: 'till-split-2',
                alt: 'Two payment rows, GCash 40 and Cash 26, and a Complete sale button.',
                marks: [
                    { type: 'look', target: 'rows', label: 'Payment rows', detail: 'GCash ₱40 plus Cash ₱26 pays the ₱66 total exactly.' },
                    { type: 'click', target: 'complete', label: 'Click Complete sale', detail: 'When the rows add up to the total, click Complete sale.' },
                ],
            },
            warning: 'The button says **Short by** and stays disabled until the rows add up to at least the total.',
        },
        {
            title: 'What if the customer has not paid enough?',
            body: 'If the payment is less than the total, the amber line says **Balance remaining** and the green button becomes **Short by ₱…**. You cannot complete the sale. Ask for the rest, or change an amount.',
            figure: {
                shot: 'till-tender-short',
                alt: 'The Payment screen where the amount is too low, showing a balance remaining and a disabled Short by button.',
                marks: [
                    { type: 'look', target: 'balance', label: 'Balance remaining', detail: 'The amount still to be paid.' },
                    { type: 'avoid', target: 'complete', label: 'Cannot click yet', detail: 'The button is disabled until the payment covers the total.' },
                ],
            },
        },
    ],
    done: 'You can take any kind of payment. Learn the Senior Citizen and PWD discount next.',
    next: ['senior-pwd', 'find-sale'],
};

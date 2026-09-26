export default {
    slug: 'manual-discount',
    category: 'till',
    title: 'Give a manual discount (managers)',
    summary: 'Take an amount off one line, or off the whole sale, when you have the authority to.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 3,
    before: ['You are signed in as a **Manager** or **Admin**. Cashiers do not see discount boxes on a sale.', 'You are on the **Register** screen with the items in the cart.'],
    steps: [
        {
            title: 'Find the discount boxes',
            body: 'Under each product line there is a small **Discount** box, in peso. Above the total there is an **order discount** box for the whole sale. Both start at `0.00`.',
            figure: {
                shot: 'till-discount-manager',
                alt: 'A cart with a discount box on a line and an order discount box above the total.',
                marks: [
                    { type: 'type', target: 'line', label: 'Line discount', detail: 'Type an amount to take off this one line, for example:', text: '5.00' },
                    { type: 'look', target: 'order', label: 'Whole sale', detail: 'Use the order discount box for an amount off the whole sale.' },
                    { type: 'look', target: 'total', label: 'New total', detail: 'The total updates at once.' },
                ],
            },
        },
        {
            title: 'Check the total and charge as usual',
            body: 'The total under the discount is what the customer pays. A discount can never make a line go below zero. Click **Charge** and finish the sale normally.',
            figure: {
                shot: 'till-discount-manager',
                alt: 'The cart total after the discount and the Senior Citizen box below it.',
                marks: [{ type: 'look', target: 'sc', label: 'Different discount', detail: 'The Senior Citizen / PWD box is separate and is available to cashiers too.' }],
            },
            note: 'Every discount is recorded in the audit log as **Discount applied**, with who gave it.',
            donts: ['Don’t give a discount to please someone if it is not store policy. Reports show every discount by amount.'],
        },
    ],
    done: 'Discounts are recorded and reported. See the Discounts report for a summary.',
    next: ['reports'],
};

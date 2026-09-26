export default {
    slug: 'senior-pwd',
    category: 'till',
    title: 'Give a Senior Citizen or PWD discount',
    summary: 'Apply the statutory discount at the till: tick the box, record the customer’s ID, choose the rule, and let the system work out the total.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 5,
    before: [
        'The customer has shown a valid **Senior Citizen** or **PWD** ID and you have looked at it.',
        'The customer’s items are in the cart. Any cashier can give this discount. It is the customer’s own entitlement, not a favour.',
        'You know which rule your store uses. If you are unsure, ask the owner or a manager before you charge.',
    ],
    steps: [
        {
            title: 'Add the items, then tick the discount box',
            body: 'Ring up the items as usual. Above the total, tick **Senior Citizen / PWD discount**. More fields open.',
            figure: {
                shot: 'till-discount-sc',
                alt: 'The cart with the Senior Citizen / PWD discount box ticked and its fields open.',
                marks: [{ type: 'click', target: 'check', label: 'Tick the box', detail: 'Tick Senior Citizen / PWD discount.' }],
            },
        },
        {
            title: 'Choose who is getting it and type the ID',
            body: 'Choose **Senior Citizen** or **PWD**. Type the **ID number** and the **name on the ID**, exactly as printed. Both are required, and **Charge** stays disabled until you have filled them in.',
            figure: {
                shot: 'till-discount-sc',
                alt: 'The discount fields with Senior Citizen chosen and an ID number and name typed in.',
                marks: [
                    { type: 'click', target: 'type', label: 'Senior Citizen or PWD', detail: 'Choose Senior Citizen or PWD.' },
                    { type: 'type', target: 'id', label: 'ID number', detail: 'ID number, from the card:', text: 'OSCA-2026-000123' },
                    { type: 'type', target: 'name', label: 'Name on the ID', detail: 'Name on the ID:', text: 'Lolo Ben Mercado' },
                ],
            },
            dos: ['Copy the ID number and name exactly. They are printed on the invoice and kept in the audit log.'],
            donts: ['Don’t use this box for a customer who has no valid ID.'],
        },
        {
            title: 'Choose the rule',
            body: '**20% + no VAT** is the standard Senior Citizen and PWD discount: 20% off, with VAT taken out where your store charges VAT. **5% basic goods** is the separate 5% discount on basic necessities and prime commodities, limited to ₱125 of discount a week. For 5%, read the amount already discounted this week from the customer’s purchase booklet and type it in.',
            figure: {
                shot: 'till-discount-sc',
                alt: 'The two rule buttons under the ID fields.',
                marks: [{ type: 'click', target: 'rule', label: 'Pick the rule', detail: 'Choose 20% + no VAT, or 5% basic goods.' }],
            },
            warning: 'The till does not check which products qualify for the 5% rule. Ring up only qualifying items on that sale.',
        },
        {
            title: 'Charge and check the receipt',
            body: 'Click **Charge**. The total shown before payment is the normal price, which is always enough to cover the discounted amount, so the customer is never short. The real, lower total is worked out when you complete the sale and shown on the receipt screen. The customer pays that **Grand total**. If they already handed over more, give back the **Change** shown.',
            figure: {
                shot: 'till-receipt-discount',
                alt: 'The sale complete screen where the grand total is lower than the amount tendered.',
                marks: [
                    { type: 'look', target: 'numbers', label: 'Check the real total', detail: 'The Grand total and Change here already include the discount.' },
                    { type: 'click', target: 'new', label: 'Click New sale', detail: 'Click New sale for the next customer.' },
                ],
            },
            tip: 'On the Payment screen, tap **Exact** or the amount the customer handed over. The till needs at least the preview total before it lets you complete the sale, and the receipt then shows the true total and change.',
        },
    ],
    done: 'The discount is recorded on the invoice and in the audit log with the ID and name.',
    next: ['pay-other', 'find-sale'],
};

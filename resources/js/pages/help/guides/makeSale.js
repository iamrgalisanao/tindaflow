export default {
    slug: 'make-sale',
    category: 'till',
    title: 'Ring up a sale',
    summary: 'Add products by tapping, searching or scanning, fix the cart, take cash, and finish with a receipt.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    startHere: ['CASHIER'],
    minutes: 6,
    before: ['Your shift is open (see “Start your shift”).', 'You are on the **Register** screen.'],
    steps: [
        {
            title: 'Tap a product to add it',
            body: 'Tap a product’s tile to put one in the cart. Tap it again for another. The category tabs above the tiles (**All**, **Beverages**, **Groceries**…) help you find things faster.',
            figure: {
                shot: 'till-register-empty',
                alt: 'The register with product tiles on the right.',
                marks: [{ type: 'click', target: 'products', label: 'Tap a product', detail: 'Tap the tile of a product to add one to the cart.' }],
            },
        },
        {
            title: 'Or search by name',
            body: 'Type part of a name or code in the search box and press **Enter** (or click **Search**). Tap the product you want. Click **Back to all products** to return to the tiles.',
            figure: {
                shot: 'till-search-results',
                alt: 'The search box showing the word coffee and one matching product.',
                marks: [
                    { type: 'type', target: 'search', label: 'Type a name', detail: 'Type part of the name, then press Enter:', text: 'coffee' },
                    { type: 'click', target: 'results', label: 'Tap the product', detail: 'Tap the product in the results to add it.' },
                    { type: 'click', target: 'back', label: 'Back to all products', detail: 'Click Back to all products to see every tile again.' },
                ],
            },
        },
        {
            title: 'Or scan the barcode',
            body: 'Point the scanner at the barcode. It types the number and presses Enter for you. The product goes straight into the cart and a green line says **Added** and its name.',
            figure: {
                shot: 'till-scan-added',
                alt: 'A green notice saying a product was added after a barcode scan, with the product in the cart.',
                marks: [
                    { type: 'look', target: 'notice', label: 'Added', detail: 'A green line confirms what was scanned.' },
                    { type: 'look', target: 'cart', label: 'In the cart', detail: 'The scanned product is now a line in the cart.' },
                ],
            },
            tip: 'Scan the same item again to add another one. No need to type a quantity for each.',
        },
        {
            title: 'Fix the cart',
            body: 'Use **−** and **+** to change how many of a line. You can also type a number in the quantity box, for example 3 sachets. Tap **×** to take a line out. The **Total amount due** at the bottom updates as you go.',
            figure: {
                shot: 'till-cart',
                alt: 'A cart with three lines, quantity buttons, a remove button, the total and a Charge button.',
                marks: [
                    { type: 'click', target: 'less', label: 'One less', detail: 'Click − for one fewer.' },
                    { type: 'type', target: 'qty', label: 'Type a quantity', detail: 'Or type the quantity yourself:', text: '3' },
                    { type: 'click', target: 'more', label: 'One more', detail: 'Click + for one more.' },
                    { type: 'click', target: 'remove', label: 'Remove a line', detail: 'Click × to remove a product that was added by mistake.' },
                    { type: 'look', target: 'total', label: 'The total', detail: 'The total the customer owes.' },
                ],
            },
            note: 'The total here is a preview. The server works out the final figure when the sale is finished, and the receipt shows that one.',
        },
        {
            title: 'Click Charge',
            body: 'When the customer is ready to pay, click the green **Charge** button. It shows the amount. The screen switches to **Payment**.',
            figure: {
                shot: 'till-cart',
                alt: 'The green Charge button under the cart total.',
                marks: [{ type: 'click', target: 'charge', label: 'Click Charge', detail: 'Click Charge.' }],
            },
            warning: 'Charge stays grey until there is something in the cart. If you leave the till with items in the cart, you are asked first, because the cart is not saved until you take payment.',
        },
        {
            title: 'Take the cash',
            body: 'For a cash sale, **CASH** is already chosen. Type what the customer handed over, use the number pad, or tap a quick amount. **Exact** fills in the total. The green line shows the **Change due**.',
            figure: {
                shot: 'till-tender',
                alt: 'The Payment screen with payment methods, an amount box, quick cash buttons and a number pad.',
                marks: [
                    { type: 'look', target: 'summary', label: 'What is being paid for', detail: 'The order summary on the left repeats the items and the total.' },
                    { type: 'click', target: 'quick', label: 'Exact', detail: 'Tap Exact if the customer pays the exact total.' },
                ],
            },
        },
        {
            title: 'Check the change, then complete the sale',
            body: 'Tap the note the customer gave, for example **₱200**. The **Change due** appears. Hand over that change, then click **Complete sale**.',
            figure: {
                shot: 'till-tender-change',
                alt: 'The Payment screen after tapping ₱200, showing change due and a Complete sale button.',
                marks: [
                    { type: 'click', target: 'quick200', label: 'Tap the note', detail: 'Tap the amount the customer handed over, for example ₱200.' },
                    { type: 'look', target: 'change', label: 'Change due', detail: 'The change to give back.' },
                    { type: 'click', target: 'complete', label: 'Click Complete sale', detail: 'Click Complete sale to finish.' },
                ],
            },
            donts: ['Don’t click Complete sale before you have the money in your hand.'],
        },
        {
            title: 'Print the invoice and start the next sale',
            body: 'The **Sale complete** screen shows the transaction number, invoice number, total, amount tendered and change. Click **Print invoice** if the customer wants a copy, then **New sale**.',
            figure: {
                shot: 'till-receipt',
                alt: 'The Sale complete screen with the transaction and invoice numbers, totals, a Print invoice button and a New sale button.',
                marks: [
                    { type: 'look', target: 'numbers', label: 'The sale record', detail: 'The transaction and invoice numbers identify this sale forever.' },
                    { type: 'click', target: 'print', label: 'Click Print invoice', detail: 'Click Print invoice to print the customer’s copy.' },
                    { type: 'click', target: 'new', label: 'Click New sale', detail: 'Click New sale for the next customer.' },
                ],
            },
            note: 'The first print is the original. Any print after that is marked **REPRINT — COPY** and recorded, so no unmarked duplicate can be made. You can print a copy later from **Lookup**.',
        },
    ],
    done: 'That is the whole sale. Next, learn how to take GCash or split a payment, and how to look up an earlier sale.',
    next: ['pay-other', 'find-sale', 'senior-pwd'],
};

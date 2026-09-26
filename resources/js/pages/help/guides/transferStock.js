export default {
    slug: 'transfer-stock',
    category: 'stock',
    title: 'Move stock between locations',
    summary: 'Move products from the backroom to the counter, or between any two locations of your store.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 4,
    before: ['Your store has **two or more locations**. An admin adds them under Store Setup, then Inventory Locations.', 'You are signed in as a **Manager** or **Admin**, on an **enrolled** browser.'],
    steps: [
        {
            title: 'Open Transfers and click Move stock',
            body: 'Open **Inventory**, then **Transfers**. Click **Move stock**.',
            figure: {
                shot: 'transfers-empty',
                alt: 'The Stock transfers page with a Move stock button.',
                marks: [{ type: 'click', target: 'move', label: 'Click Move stock', detail: 'Click Move stock.' }],
            },
            note: 'Sales take stock from your **default** location. If you move stock to another location, it can no longer be sold from the till until you move it back.',
        },
        {
            title: 'Choose where from and where to',
            body: 'Choose **From** and **To**. Search for a product, click it, type the **Quantity** and click **Add**. The panel shows how many are on hand at the source. Add each product you are moving. A **Note** is optional.',
            figure: {
                shot: 'transfers-form',
                alt: 'The Move stock panel with From and To locations, a product line and a note.',
                marks: [
                    { type: 'click', target: 'from', label: 'From', detail: 'Choose the location the stock is leaving.' },
                    { type: 'click', target: 'to', label: 'To', detail: 'Choose the location it is going to.' },
                    { type: 'type', target: 'note', label: 'Note', detail: 'A note, optional:', text: 'Restock the backroom shelf' },
                    { type: 'click', target: 'move', label: 'Click Move stock', detail: 'Click Move stock.' },
                ],
            },
            warning: 'A transfer takes effect at once and cannot be edited. If it was a mistake, move the stock back.',
        },
        {
            title: 'See the transfer in the list',
            body: 'The list shows when it happened, the two locations, how many products and the note. Click **View** to see the lines.',
            figure: {
                shot: 'transfers-done',
                alt: 'The Stock transfers list with the new transfer.',
                marks: [{ type: 'look', target: 'row', label: 'Your transfer', detail: 'Store shelf to Backroom, with the note.' }],
            },
        },
    ],
    done: 'Stock has moved. Check the Stock page to see it at both locations.',
    next: ['stock-count'],
};

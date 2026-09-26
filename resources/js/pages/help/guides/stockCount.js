export default {
    slug: 'stock-count',
    category: 'stock',
    title: 'Count the shelf and correct the stock',
    summary: 'Count what is really on a shelf, compare it with the system, and post the difference.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 8,
    before: ['You are signed in as a **Manager** or **Admin**, on an **enrolled** browser.', 'You are ready to count one shelf or one room. Products you do not count are left alone.'],
    steps: [
        {
            title: 'Start a count',
            body: 'Open **Inventory**, then **Counts**, and click **Start a count**. Choose the **Location** and add an optional **Note** such as “Aisle 2” or “Month-end count”. Click **Start count**.',
            figure: {
                shot: 'counts-start-form',
                alt: 'The Start a stock count panel with a location and a note.',
                marks: [
                    { type: 'click', target: 'location', label: 'Location', detail: 'Choose where you are counting.' },
                    { type: 'type', target: 'note', label: 'Note', detail: 'A note to recognise it later:', text: 'Aisle 2, month-end count' },
                    { type: 'click', target: 'start', label: 'Click Start count', detail: 'Click Start count.' },
                ],
            },
            note: 'Only one count can be in progress for a location at a time. Sales made while you count are kept.',
        },
        {
            title: 'Add each product you counted',
            body: 'Type the product in the search box and click it. Then type the number you **found** and press **Enter**. Repeat for each product on the shelf.',
            figure: {
                shot: 'counts-search',
                alt: 'The count screen with a product search and a matching product.',
                marks: [
                    { type: 'type', target: 'search', label: 'Search', detail: 'Search for a product you counted:', text: 'Corn Chips' },
                    { type: 'click', target: 'first', label: 'Click the product', detail: 'Click the product, then type the number you found and press Enter.' },
                ],
            },
            tip: 'Count with your eyes on the shelf, not on the screen. The system’s number is shown next to yours only to help you notice a difference.',
        },
        {
            title: 'Compare with the system',
            body: 'The table shows **System expected**, what you **Found** and the **Difference**. A plus means more than expected, a minus means less. You can change a found number or remove a line.',
            figure: {
                shot: 'counts-lines',
                alt: 'A table of counted products with system expected, found and difference columns.',
                marks: [
                    { type: 'look', target: 'summary', label: 'Summary', detail: 'How many products differ and by how much.' },
                    { type: 'type', target: 'found', label: 'Found', detail: 'Change a number if you miscounted:', text: '3' },
                    { type: 'look', target: 'diff', label: 'Difference', detail: 'Plus is extra, minus is missing.' },
                ],
            },
        },
        {
            title: 'Post the count',
            body: 'When you are happy, click **Post count**, then confirm. Posting sets the recorded stock of the counted products to what you found. It cannot be undone; if it was wrong, correct it with another count or an adjustment. Click **Discard count** instead if you want to abandon it.',
            figure: {
                shot: 'counts-post-confirm',
                alt: 'A confirmation asking whether to post the count.',
                marks: [
                    { type: 'look', target: 'dialog', label: 'Cannot be undone', detail: 'Read this once. Posting changes the recorded stock.' },
                    { type: 'click', target: 'post', label: 'Click Post count', detail: 'Click Post count to confirm.' },
                ],
            },
            warning: 'Products you did not add to the count are not changed at all.',
        },
        {
            title: 'Check the result',
            body: 'The count is now **POSTED**. The movements list shows each correction with the count as the reason.',
            figure: {
                shot: 'counts-posted',
                alt: 'A posted stock count with its lines.',
                marks: [{ type: 'look', target: 'table', label: 'Posted', detail: 'What was counted and what changed.' }],
            },
        },
    ],
    done: 'The stock now matches the shelf.',
    next: ['transfer-stock', 'reports'],
};

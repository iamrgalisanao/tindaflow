export default {
    slug: 'receive-stock',
    category: 'stock',
    title: 'Receive stock and correct mistakes',
    summary: 'Record deliveries and opening stock, spot what is running low, and fix damaged or miscounted items.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 8,
    before: ['You are signed in as a **Manager** or **Admin**.', 'This browser is **enrolled as a terminal**. Stock changes are recorded against a till, so an un-enrolled browser can view stock but not change it.', 'The products already exist in the catalog.'],
    steps: [
        {
            title: 'Open Stock and click Receive stock',
            body: 'Open **Inventory**, then **Stock**. This page lists what is on hand for every product you track. When it is empty, click **Receive stock** to record what you have.',
            figure: {
                shot: 'stock-empty',
                alt: 'The empty Stock page with Receive stock and Adjust stock buttons.',
                marks: [
                    { type: 'click', target: 'receive', label: 'Click Receive stock', detail: 'Click Receive stock.' },
                    { type: 'avoid', target: 'adjust', label: 'Not for deliveries', detail: 'Don’t use Adjust stock for a delivery. Adjustments are for corrections.' },
                ],
            },
        },
        {
            title: 'Find the product',
            body: 'Type part of the name, SKU or barcode in the **Product** box and click the product in the list.',
            figure: {
                shot: 'stock-receive-search',
                alt: 'The Receive stock panel with the word Sugar typed and one matching product below.',
                marks: [
                    { type: 'type', target: 'product', label: 'Type to search', detail: 'Type part of the name, SKU or barcode:', text: 'Sugar' },
                    { type: 'click', target: 'first', label: 'Click the product', detail: 'Click the product in the list.' },
                ],
            },
        },
        {
            title: 'Say what kind of stock it is, how many, and record it',
            body: 'Choose **Purchase receipt** for stock a supplier just delivered, or **Opening stock** for what is already on the shelf when you start. Type the **Quantity**. You can add the **Unit cost** and a **Note** such as a delivery receipt number. The panel shows what is on hand now and what it will be after. Click **Record receipt**.',
            figure: {
                shot: 'stock-receive-form',
                alt: 'The Receive stock panel with type, quantity, unit cost and a note filled in.',
                marks: [
                    { type: 'click', target: 'type', label: 'Purchase or opening', detail: 'Choose Purchase receipt or Opening stock.' },
                    { type: 'type', target: 'qty', label: 'Quantity', detail: 'Quantity received:', text: '40' },
                    { type: 'type', target: 'cost', label: 'Unit cost', detail: 'What you paid for one, if you know:', text: '58' },
                    { type: 'type', target: 'note', label: 'Note', detail: 'A note to explain it later:', text: 'Delivery from Ramos Trading, receipt no. 1042' },
                    { type: 'click', target: 'record', label: 'Click Record receipt', detail: 'Click Record receipt.' },
                ],
            },
            note: 'Every receipt is a permanent record. You cannot edit one later; you correct a mistake with an adjustment.',
        },
        {
            title: 'Check what is on hand and what is running low',
            body: 'The **Stock** list shows on-hand quantity for each product and location. A red **LOW** tag means it has reached its **reorder level**. Click **Low stock** above the list to see only those.',
            figure: {
                shot: 'stock-list',
                alt: 'The Stock list with on-hand quantities and low stock tags.',
                marks: [
                    { type: 'look', target: 'table', label: 'On hand', detail: 'One row per product and location, with the reorder level beside it.' },
                    { type: 'click', target: 'filter', label: 'Click Low stock', detail: 'Click Low stock to see only what needs reordering.' },
                ],
            },
        },
        {
            title: 'Fix a mistake with an adjustment',
            body: 'Click **Adjust stock**, choose the product, then **What happened**: **Add stock**, **Remove stock**, **Damaged** or **Expired**. Type the **Quantity** and a **Reason**, which is kept so the change can be explained later. Click **Record adjustment**.',
            figure: {
                shot: 'stock-adjust-form',
                alt: 'The Adjust stock panel with Damaged chosen, a quantity of 2 and a reason.',
                marks: [
                    { type: 'click', target: 'kind', label: 'What happened', detail: 'Choose what happened.' },
                    { type: 'type', target: 'qty', label: 'Quantity', detail: 'How many:', text: '2' },
                    { type: 'type', target: 'reason', label: 'Reason', detail: 'Reason, required:', text: 'Two bottles crushed when the crate fell.' },
                    { type: 'click', target: 'record', label: 'Click Record adjustment', detail: 'Click Record adjustment.' },
                ],
            },
            donts: ['Don’t hide a shortage with a plus adjustment. If stock keeps going missing, find out why.'],
        },
        {
            title: 'See the full history',
            body: 'Open **Inventory**, then **Movements**. Every change to stock is listed, newest first: sales, receipts, adjustments, counts and transfers. Rows are never edited or deleted.',
            figure: {
                shot: 'stock-movements',
                alt: 'The Stock movements list showing receipts, sales and adjustments.',
                marks: [
                    { type: 'click', target: 'filters', label: 'Filter', detail: 'Filter by product, type or dates.' },
                    { type: 'look', target: 'table', label: 'Every change', detail: 'Type, quantity, location and the reason for each change.' },
                ],
            },
        },
    ],
    done: 'Stock is recorded. Sales will now lower these numbers automatically.',
    next: ['stock-count', 'transfer-stock', 'packs-barcodes'],
};

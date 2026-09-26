export default {
    slug: 'edit-product',
    category: 'stock',
    title: 'Change a price or retire a product',
    summary: 'Update a product’s price or name, or take it off sale without losing its history.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 4,
    before: ['You are signed in as a **Manager** or **Admin**.'],
    steps: [
        {
            title: 'Find the product',
            body: 'Open **Catalog**, then **Products**. Type part of the name or SKU in **Search**, or use the **Category** and **Status** filters. Click **Edit** on the product’s row.',
            figure: {
                shot: 'catalog-products-list',
                alt: 'The Products page with a search box and a table of products.',
                marks: [
                    { type: 'type', target: 'search', label: 'Search', detail: 'Search by name or SKU:', text: 'sugar' },
                    { type: 'look', target: 'table', label: 'Click Edit on the row', detail: 'Click Edit at the right end of the product’s row.' },
                ],
            },
        },
        {
            title: 'Change the price and save',
            body: 'Type the new **Selling price**, then click **Save changes**. From now on the till charges the new price.',
            figure: {
                shot: 'catalog-edit-bottom',
                alt: 'The lower part of the Edit product panel with the price, a Deactivate product button and Save changes.',
                marks: [
                    { type: 'type', target: 'price', label: 'New price', detail: 'Selling price, the new price:', text: '75.00' },
                    { type: 'click', target: 'save', label: 'Click Save changes', detail: 'Click Save changes.' },
                    { type: 'avoid', target: 'deactivate', label: 'Not for a price change', detail: 'Don’t click Deactivate product to change a price. It takes the product off sale.' },
                ],
            },
            note: 'Old sales keep the price they were sold at. Changing a price never rewrites past receipts or reports.',
        },
        {
            title: 'Retire a product you no longer sell',
            body: 'Open the product with **Edit**, scroll to the bottom and click **Deactivate product**, or click **Deactivate** on its row. Confirm. It can no longer be sold, but it stays in every past sale. You can **Reactivate** it later from the **Inactive** filter.',
            figure: {
                shot: 'catalog-deactivate',
                alt: 'A confirmation asking whether to deactivate a product.',
                marks: [
                    { type: 'look', target: 'dialog', label: 'Confirm first', detail: 'You are asked to confirm before the product is taken off sale.' },
                    { type: 'click', target: 'confirm', label: 'Click Deactivate', detail: 'Click Deactivate to confirm. Click Cancel if it was a slip.' },
                ],
            },
            tip: 'If a customer brings an old product to the till, the till tells the cashier it is inactive and cannot be sold. See “When something looks wrong”.',
        },
    ],
    done: 'Prices and products are up to date.',
    next: ['packs-barcodes', 'receive-stock'],
};

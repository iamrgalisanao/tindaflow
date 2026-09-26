export default {
    slug: 'add-products',
    category: 'stock',
    title: 'Add your products',
    summary: 'Create categories, then add each product with its price, tax class and reorder level so the till can sell it.',
    roles: ['MANAGER', 'ADMIN'],
    startHere: ['MANAGER'],
    minutes: 8,
    before: ['You are signed in as a **Manager** or **Admin**.', 'You have the product’s name, selling price and, if you know it, what it costs you. The barcode is optional.'],
    steps: [
        {
            title: 'Add a category first',
            body: 'Categories group your products so the till can show them in tabs and reports can add them up. Open **Catalog**, then **Categories**. Type a name and click **Add category**. Do the same for as many as you need.',
            figure: {
                shot: 'catalog-category-form',
                alt: 'The Categories page with a Category name box and an Add category button.',
                marks: [
                    { type: 'type', target: 'name', label: 'Category name', detail: 'Category name, for example:', text: 'Groceries' },
                    { type: 'click', target: 'add', label: 'Click Add category', detail: 'Click Add category.' },
                ],
            },
            note: '**Brands** work the same way, under Catalog then Brands. A category or brand can be added later, even from inside the product form with **+ New category** or **+ New brand**.',
            warning: 'Categories and brands cannot be renamed or deleted yet. Check the spelling before you add one.',
        },
        {
            title: 'Open Products and click New product',
            body: 'Open **Catalog**, then **Products**. This is your whole catalog. Click **New product** at the top right.',
            figure: {
                shot: 'catalog-products-list',
                alt: 'The Products page with a search box, filters, a table of products and buttons for Import CSV, Export CSV and New product.',
                marks: [
                    { type: 'click', target: 'new-product', label: 'Click New product', detail: 'Click New product.' },
                    { type: 'look', target: 'table', label: 'Your catalog', detail: 'Each row is a product with its unit, cost, price and tax class.' },
                    { type: 'look', target: 'import', label: 'Many at once?', detail: 'To add many products in one go, use Import CSV instead (see the import guide).' },
                ],
            },
        },
        {
            title: 'Type the SKU, barcode and name',
            body: 'The **SKU** is your own short code for the product and must be unique. The **Barcode** is what a scanner reads; leave it empty if the product has none. Then type the **Name** the cashier will see. Pick a **Category** and **Brand** from the lists.',
            figure: {
                shot: 'catalog-product-form-top',
                alt: 'The top of the New product panel with SKU, barcode, name, category and brand filled in.',
                marks: [
                    { type: 'type', target: 'sku', label: 'SKU', detail: 'SKU, your own code:', text: 'GRO-001' },
                    { type: 'type', target: 'barcode', label: 'Barcode', detail: 'Barcode, if it has one:', text: '4800000000011' },
                    { type: 'type', target: 'name', label: 'Name', detail: 'Name the cashier will see:', text: 'Sugar (1kg)' },
                    { type: 'click', target: 'category', label: 'Pick a category', detail: 'Choose a category from the list.' },
                ],
            },
            tip: 'Put the size in the name, for example “Sugar (1kg)”. The cashier sees the name on a tile, so a clear name means fewer wrong taps.',
        },
        {
            title: 'Set the unit, prices and tax class',
            body: 'Choose the **Unit of measure** (tap a suggestion such as **pc** or **kg**). Type what it **Cost** you and its **Selling price**. Choose the **Tax class** that applies. Leave **Track inventory** ticked so sales lower the stock count, and set a **Reorder level**. Click **Create product**.',
            figure: {
                shot: 'catalog-product-form-bottom',
                alt: 'The lower part of the New product panel with unit, cost, selling price, tax class, track inventory, reorder level and a Create product button.',
                marks: [
                    { type: 'type', target: 'unit', label: 'Unit', detail: 'Unit of measure:', text: 'kg' },
                    { type: 'type', target: 'cost', label: 'Cost', detail: 'Cost, what you pay for one:', text: '58.00' },
                    { type: 'type', target: 'price', label: 'Selling price', detail: 'Selling price, what the customer pays:', text: '72.00' },
                    { type: 'look', target: 'tax', label: 'Tax class', detail: 'Tax class: VATABLE is sold with VAT. VAT EXEMPT, ZERO RATED and NON-VAT are for the other cases. Ask your accountant if you are unsure.' },
                    { type: 'type', target: 'reorder', label: 'Reorder level', detail: 'Reorder level. When stock falls to this number the product shows as low:', text: '5' },
                    { type: 'click', target: 'create', label: 'Click Create product', detail: 'Click Create product.' },
                ],
            },
            dos: ['Enter prices with two decimals, for example 72.00.', 'Keep **Track inventory** on for anything you count on the shelf.'],
            warning: 'Editing a product later changes the catalog from then on only. Past sales keep the name and price they were sold at.',
        },
        {
            title: 'Check the product in the list',
            body: 'The new product is in the table as **ACTIVE**. Cashiers can find it at the till straight away by tapping its tile, searching its name or scanning its barcode.',
            figure: {
                shot: 'catalog-products-done',
                alt: 'The Products table now showing the new products.',
                marks: [{ type: 'look', target: 'table', label: 'Products listed', detail: 'New products appear in the table and at the till.' }],
            },
        },
    ],
    done: 'Your products are in the catalog. Record how many you have on the shelf next.',
    next: ['receive-stock', 'import-products'],
};

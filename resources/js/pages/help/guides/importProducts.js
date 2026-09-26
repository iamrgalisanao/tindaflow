export default {
    slug: 'import-products',
    category: 'stock',
    title: 'Import many products from a CSV file',
    summary: 'Add or update dozens of products in one go from a spreadsheet, with a safe preview before anything changes.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['You are signed in as a **Manager** or **Admin**.', 'You have a spreadsheet program that can save as **CSV** (comma separated values).'],
    steps: [
        {
            title: 'Download the current catalog as your starting point',
            body: 'Open **Catalog**, then **Products**, and click **Import CSV**. Click **Download current catalog (CSV)**. The file already has the right column names. Open it in your spreadsheet and add a row per new product, or change the rows you want to update.',
            figure: {
                shot: 'import-preview',
                alt: 'The Import products panel with a download link, a file chooser and a preview summary.',
                marks: [{ type: 'click', target: 'download', label: 'Download the catalog', detail: 'Click Download current catalog (CSV) to get a file with the correct columns.' }],
            },
            note: 'The columns are `sku`, `barcode`, `name`, `description`, `category`, `brand`, `unit_of_measure`, `cost`, `selling_price`, `tax_class`, `track_inventory`, `reorder_level` and `active`. Products are matched on **sku**: a new SKU creates a product, an existing SKU updates it.',
        },
        {
            title: 'Choose your file and read the preview',
            body: 'Save the sheet as CSV. In the panel, click **CSV file** and choose it. The system checks the file first and shows what would happen. **Nothing has been changed yet.** Look at **Will create**, **Will update** and **Problems**.',
            figure: {
                shot: 'import-preview',
                alt: 'The preview after choosing a file, showing three products will be created and no problems.',
                marks: [
                    { type: 'click', target: 'file', label: 'Choose your CSV file', detail: 'Click the file box and choose your saved CSV file.' },
                    { type: 'look', target: 'summary', label: 'The preview', detail: 'Will create, Will update, Unchanged and Problems. Fix any Problems in the sheet and choose the file again.' },
                ],
            },
            donts: ['Don’t click Import while the Problems number is above zero unless you understand why.'],
        },
        {
            title: 'Click Import',
            body: 'When the preview looks right, click **Import** (it shows how many products). The panel then says **Import finished** and how many were created, updated or skipped.',
            figure: {
                shot: 'import-preview',
                alt: 'The Import button at the bottom of the panel.',
                marks: [{ type: 'click', target: 'import', label: 'Click Import', detail: 'Click the Import button. It states how many products it will bring in.' }],
            },
            tip: 'You can Export CSV from the Products page at any time to keep a backup copy of your catalog.',
        },
    ],
    done: 'Your products are imported. Open **Products** to see them, then record their opening stock.',
    next: ['receive-stock', 'packs-barcodes'],
};

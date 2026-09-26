export default {
    slug: 'packs-barcodes',
    category: 'stock',
    title: 'Packs and extra barcodes',
    summary: 'Sell by the piece but receive by the case, and let more than one barcode scan as the same product.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 5,
    before: ['The product already exists in the catalog.', 'You know how many pieces are in a case (or other pack).'],
    steps: [
        {
            title: 'Open the product and add a pack',
            body: 'Open **Catalog**, then **Products**, and click **Edit** on the product. Under **Packs and other barcodes**, type a pack **Name** (for example `Case`) and how many pieces it holds in **Units** (for example `24`). A barcode is optional. Click **Add**.',
            figure: {
                shot: 'packs-form',
                alt: 'The Packs and other barcodes section of the Edit product panel with a pack name, units and a barcode.',
                marks: [
                    { type: 'type', target: 'name', label: 'Pack name', detail: 'Pack name:', text: 'Case' },
                    { type: 'type', target: 'units', label: 'Units', detail: 'How many pieces are in it:', text: '24' },
                    { type: 'type', target: 'barcode', label: 'Barcode', detail: 'The case’s barcode, optional:', text: '4800000000110' },
                    { type: 'click', target: 'add', label: 'Click Add', detail: 'Click Add.' },
                ],
            },
            note: 'Stock is always counted in the product’s own unit, for example pieces. A pack only says how many pieces it holds. Packs and barcodes are added or removed at once, without Save changes.',
        },
        {
            title: 'Check the pack is listed',
            body: 'The pack appears in the list, for example **Case × 24 pc**. Scanning its barcode finds this product.',
            figure: {
                shot: 'packs-done',
                alt: 'The list of packs and barcodes showing Case × 24 pc.',
                marks: [{ type: 'look', target: 'list', label: 'Packs listed', detail: 'Each pack, with how many it holds and its barcode.' }],
            },
        },
        {
            title: 'Receive stock by the case',
            body: 'When you receive stock for this product, the panel shows **Received as** with **Single pc** and **Case**. Choose **Case** and type how many cases. The system turns 2 cases into 48 pieces for you.',
            figure: {
                shot: 'stock-receive-pack',
                alt: 'The Receive stock panel with a choice between Single pc and Case.',
                marks: [
                    { type: 'click', target: 'case', label: 'Choose Case', detail: 'Choose Case.' },
                    { type: 'type', target: 'qty', label: 'Number of cases', detail: 'How many cases arrived:', text: '2' },
                ],
            },
            tip: 'You can also add an extra barcode without a pack: leave the name empty and just scan the code. It will then find the same product.',
        },
    ],
    done: 'Deliveries by the case now update the piece count correctly.',
    next: ['receive-stock'],
};

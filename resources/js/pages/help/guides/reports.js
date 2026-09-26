export default {
    slug: 'reports',
    category: 'manage',
    title: 'Read your reports',
    summary: 'Find the right report, choose the dates, read the totals and export the figures to a spreadsheet.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['You are signed in as a **Manager** or **Admin**. Reports need the report permission.'],
    steps: [
        {
            title: 'Pick a report',
            body: 'Open **Reports**. The tabs group them: **Sales Reports**, **Tax & VAT**, **Exceptions & Audits**, **Inventory & Stock** and **Cash & Shifts**. Each card says what it shows. Click the one you want, for example **Daily Sales Summary**.',
            figure: {
                shot: 'reports-hub',
                alt: 'The Reports page with category tabs and report cards.',
                marks: [
                    { type: 'click', target: 'tabs', label: 'Choose a group', detail: 'Choose a group, or leave All.' },
                    { type: 'click', target: 'card', label: 'Open a report', detail: 'Click a report’s card to open it.' },
                ],
            },
            note: 'The menu on the left also lists every report under **Reports**.',
        },
        {
            title: 'Choose the dates and read the numbers',
            body: 'Use **Today**, **Yesterday**, **This week** or **This month**, or type **From** and **To** dates and click **Apply**. The cards at the top show the main totals. The table underneath breaks them down.',
            figure: {
                shot: 'reports-viewer',
                alt: 'A report with date shortcuts, From and To dates, summary cards, a table and an Export CSV button.',
                marks: [
                    { type: 'click', target: 'presets', label: 'Quick dates', detail: 'Click a quick date range.' },
                    { type: 'click', target: 'apply', label: 'Click Apply', detail: 'Or set From and To yourself and click Apply.' },
                    { type: 'look', target: 'cards', label: 'The totals', detail: 'Gross sales, discounts and grand total for the dates you chose.' },
                    { type: 'look', target: 'table', label: 'The breakdown', detail: 'One row per business day with the VAT split. Voided sales are not counted.' },
                ],
            },
            note: 'Read the short description under each report’s name. It says what is counted and what is left out, for example whether voided sales are included.',
        },
        {
            title: 'Export to a spreadsheet',
            body: 'Click **Export CSV** to download the figures for the dates you chose. Open it in a spreadsheet program to sort, chart or share it with your accountant.',
            figure: {
                shot: 'reports-viewer',
                alt: 'The Export CSV button at the top right of a report.',
                marks: [{ type: 'click', target: 'export', label: 'Click Export CSV', detail: 'Click Export CSV.' }],
            },
        },
        {
            title: 'Check what is running low',
            body: 'The **Low Stock** report lists every product at or below its reorder level, across all locations. It is also under **Inventory**, then **Low stock**. Check it before you order.',
            figure: {
                shot: 'reports-low-stock',
                alt: 'The Low Stock report listing products at or below their reorder level.',
                marks: [{ type: 'look', target: 'table', label: 'Reorder these', detail: 'Products at or below their reorder level.' }],
            },
        },
    ],
    done: 'There are twenty reports. Start with Daily Sales Summary, Sales by Product and Low Stock.',
    next: ['close-business-day', 'records'],
};

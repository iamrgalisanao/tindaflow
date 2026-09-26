export default {
    slug: 'setup-store',
    category: 'setup',
    title: 'Get the store ready to sell',
    summary: 'The one-time set-up an admin does before the first sale: business details, tax registration, fiscal installation, invoice numbers and a stock location.',
    roles: ['ADMIN'],
    startHere: ['ADMIN'],
    minutes: 12,
    before: [
        'You are signed in as an **Admin**. Managers and cashiers do not see these screens.',
        'You have your business papers to hand: the registered name, the **TIN**, the address, and whether the business is VAT-registered.',
        'If you are unsure what to type in a tax or invoice box, ask the owner or your accountant first. These details print on every invoice.',
    ],
    steps: [
        {
            title: 'See what is still missing',
            body: 'Open **Store Setup** in the menu. The **Readiness** list shows the four things the till needs before it can complete a sale. Each says **INCOMPLETE** until you have done it. You will work down this list.',
            figure: {
                shot: 'setup-overview-empty',
                alt: 'The Store Setup overview listing four readiness checks, all marked incomplete.',
                marks: [
                    { type: 'look', target: 'checks', label: 'Four checks', detail: 'Fiscal Installation, Invoice Series, Inventory Location and Tax Registration. All four must say READY.' },
                    { type: 'click', target: 'nav-business', label: 'Start here', detail: 'Click Business Details in the Store Setup menu to fill in who you are first.' },
                ],
            },
            note: 'If you try to sell before all four are ready, the till stops and tells you which one is missing. Nothing is lost, you just finish the set-up.',
        },
        {
            title: 'Fill in your business details',
            body: 'These are printed at the top of every invoice. Type the **Business name** (what customers call you) and the **Registered name** (the name on your registration). Then the **TIN**, an optional **Branch code**, and your **Business address**.',
            figure: {
                shot: 'setup-business-top',
                alt: 'The Business details form with the business name, registered name, TIN, branch code and address filled in.',
                marks: [
                    { type: 'type', target: 'business-name', label: 'Business name', detail: 'Business name, the name customers know you by:', text: "Aling Nena's Sari-Sari Store" },
                    { type: 'type', target: 'registered-name', label: 'Registered name', detail: 'Registered name, exactly as on your registration:', text: 'Elena D. Ramos' },
                    { type: 'type', target: 'tin', label: 'TIN', detail: 'TIN (the sample shows a dummy number):', text: '000-000-000-000' },
                    { type: 'type', target: 'address', label: 'Address', detail: 'Business address:', text: '12 Mabini Street, Quezon City' },
                ],
            },
            dos: ['Copy the registered name and TIN exactly from your registration papers.'],
            donts: ['Don’t leave the fields marked with a green * empty. The page will not save.'],
        },
        {
            title: 'Add the header, footer and contact details, then save',
            body: 'The **Header** prints under your business details (a slogan or opening hours). The **Footer** prints at the bottom (a thank-you or a return policy). Both are optional. Scroll down and click **Save changes**. The preview on the right shows how the top of an invoice will look.',
            figure: {
                shot: 'setup-business-save',
                alt: 'The lower part of the Business details form with Header, Footer, Contact and the Save changes button.',
                marks: [
                    { type: 'type', target: 'header', label: 'Header', detail: 'Header, a short line printed under your name:', text: 'Salamat po, balik kayo!' },
                    { type: 'type', target: 'footer', label: 'Footer', detail: 'Footer, printed at the bottom:', text: 'Items may be returned within 7 days with this receipt.' },
                    { type: 'click', target: 'save', label: 'Click Save changes', detail: 'Click Save changes.' },
                ],
            },
            tip: 'Changes apply to invoices issued from now on. Invoices you already issued keep the details they were printed with, so you can fix a typo without changing history.',
        },
        {
            title: 'Register your tax status',
            body: 'Open **Tax Registrations**. Choose **VAT** if your business is VAT-registered, or **NON_VAT** if it is not. Set the date it started in **Effective from**, then click **Register**.',
            figure: {
                shot: 'setup-tax',
                alt: 'The Tax Registrations form with a type list, a date and a Register button.',
                marks: [
                    { type: 'click', target: 'type', label: 'Choose VAT or NON_VAT', detail: 'Choose VAT or NON_VAT from the list. Ask your accountant if you are not sure which applies.' },
                    { type: 'click', target: 'date', label: 'Pick the date', detail: 'Pick the date this registration started.' },
                    { type: 'click', target: 'register', label: 'Click Register', detail: 'Click Register.' },
                ],
            },
            warning: 'Registering a new tax status later closes the current one the day before the new one starts. History is never overwritten, but do not change it casually.',
        },
        {
            title: 'Record the fiscal installation and assign your till',
            body: 'Open **Fiscal Installations**. Leave **STANDALONE** for a single shop, type the **Software version**, and click **Add installation**. Then, on the installation that appears, pick your terminal (for example **DEMO-01**) and click **Assign**.',
            figure: {
                shot: 'setup-fiscal-form',
                alt: 'The Fiscal Installations form with a deployment model, software version, serial number and an Add installation button.',
                marks: [
                    { type: 'click', target: 'model', label: 'STANDALONE', detail: 'Leave the type on STANDALONE for a single shop with its own till.' },
                    { type: 'type', target: 'version', label: 'Software version', detail: 'Type the software version you were given, for example', text: '1.0.0' },
                    { type: 'click', target: 'add', label: 'Click Add installation', detail: 'Click Add installation.' },
                ],
            },
            note: 'Each terminal must be assigned to exactly one installation. The next picture shows the assigning part.',
        },
        {
            title: 'Assign the terminal to the installation',
            body: 'Under the installation you just added, open **Assign a terminal…**, choose your till, and click **Assign**. The card then says **1 terminal**.',
            figure: {
                shot: 'setup-fiscal-assign',
                alt: 'An installation card with a terminal list and an Assign button.',
                marks: [
                    { type: 'click', target: 'pick', label: 'Choose your till', detail: 'Open the list and choose your terminal.' },
                    { type: 'click', target: 'assign', label: 'Click Assign', detail: 'Click Assign.' },
                ],
            },
        },
        {
            title: 'Start an invoice series',
            body: 'Open **Invoice Series**. Choose the installation, give the series a short **Series code** (for example `MAIN`), a **Prefix** printed before the number (for example `INV-`), and the **Starting #**, usually `1`. Click **Activate**.',
            figure: {
                shot: 'setup-series-form',
                alt: 'The Invoice Series form with installation, code, prefix, starting number and an Activate button.',
                marks: [
                    { type: 'click', target: 'install', label: 'Choose the installation', detail: 'Choose the fiscal installation you just made.' },
                    { type: 'type', target: 'code', label: 'Series code', detail: 'Series code, a short name:', text: 'MAIN' },
                    { type: 'type', target: 'prefix', label: 'Prefix', detail: 'Prefix, printed before the invoice number:', text: 'INV-' },
                    { type: 'type', target: 'start', label: 'Starting #', detail: 'Starting number, normally:', text: '1' },
                    { type: 'click', target: 'activate', label: 'Click Activate', detail: 'Click Activate.' },
                ],
            },
            dos: ['Fill in **Ending #** only if your permit gives you a limited range of invoice numbers.'],
            warning: 'Only one series can be active for an installation. Close the current one before you activate another. Invoice numbers are never reused or renumbered.',
        },
        {
            title: 'Add your first stock location',
            body: 'Open **Inventory Locations**, type a name such as `Store shelf` and click **Add location**. The first location becomes the default automatically. Sales take stock from the default location.',
            figure: {
                shot: 'setup-location-form',
                alt: 'The Inventory Locations page with a Location name box and an Add location button.',
                marks: [
                    { type: 'type', target: 'name', label: 'Location name', detail: 'Location name, where you keep stock:', text: 'Store shelf' },
                    { type: 'click', target: 'add', label: 'Click Add location', detail: 'Click Add location.' },
                ],
            },
        },
        {
            title: 'Check that everything says READY',
            body: 'Go back to **Overview**. All four checks should now say **READY**. If one still says **INCOMPLETE**, click **Manage** next to it and finish it.',
            figure: {
                shot: 'setup-overview-ready',
                alt: 'The Store Setup overview with all four readiness checks marked ready.',
                marks: [{ type: 'look', target: 'checks', label: 'All READY', detail: 'All four checks say READY. The till can now complete sales.' }],
            },
        },
    ],
    done: 'The store is set up. Next, **enroll the till** so a browser is allowed to sell, and add your people and products.',
    next: ['enroll-terminal', 'manage-users', 'add-products'],
};

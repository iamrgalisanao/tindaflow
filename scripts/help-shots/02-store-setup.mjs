import { launch, login, ensureLogin, go, click, fill, input, shot, saveAnnotations, sleep, find, setValue, today, text, reload, scrollMain } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');

// ---------------------------------------------------------------- overview (all incomplete)
await go(page, '/admin/store-setup');
await shot(page, 'setup-overview-empty', {
    targets: {
        checks: { sel: 'main ul, main div.space-y-2, main section', nth: 0 },
        'manage-first': { text: 'Manage', nth: 0 },
        'nav-business': { text: 'Business Details', tag: 'a' },
    },
});

// ---------------------------------------------------------------- business details
await go(page, '/admin/store-setup/business');
const biz = [
    ['Business name', "Aling Nena's Sari-Sari Store"], ['Registered name', 'Elena D. Ramos'], ['TIN', '000-000-000-000'], ['Branch code', '000'],
    ['Business address', '12 Mabini Street, Brgy. San Roque, Quezon City'],
];
for (const [label, value] of biz) await fill(page, label, value);
await scrollMain(page, 0);
await shot(page, 'setup-business-top', {
    clip: { x: 256, y: 40, w: 944, h: 590 },
    targets: {
        'business-name': { input: 'Business name' }, 'registered-name': { input: 'Registered name' }, tin: { input: 'TIN' },
        'branch-code': { input: 'Branch code' }, address: { input: 'Business address' },
    },
});
await fill(page, 'Header', 'Salamat po, balik kayo!');
await fill(page, 'Footer', 'Items may be returned within 7 days with this receipt.');
await fill(page, 'Telephone', '0917 123 4567');
await fill(page, 'Email', 'nena@example.com');
const save = await find(page, 'Save changes');
await save.evaluate((e) => e.scrollIntoView({ block: 'center' }));
await sleep(500);
await shot(page, 'setup-business-save', {
    targets: { save: { text: 'Save changes' }, header: { input: 'Header' }, footer: { input: 'Footer' } },
});
await scrollMain(page, 0);
await shot(page, 'setup-business-preview', { clip: { x: 846, y: 140, w: 354, h: 270 }, targets: { 'tax-card': { closest: 'div.rounded-lg', of: { text: 'Tax registration', tag: 'h3' } }, preview: { closest: 'div.rounded-lg', of: { text: 'Top of your invoices', tag: 'h3' } } } });
await click(page, 'Save changes');
await sleep(1200);
await go(page, '/admin/store-setup/business');
await shot(page, 'setup-business-saved', { targets: { 'no-tax': { text: 'No tax registration is on record', exact: false, tag: 'p' }, manage: { text: 'Manage tax registrations' } } });

// ---------------------------------------------------------------- tax registration
await go(page, '/admin/store-setup/tax-registrations');
{
    const date = await page.$('input[type=date]');
    await setValue(page, date, today());
}
await shot(page, 'setup-tax', { clip: { x: 256, y: 40, w: 944, h: 300 }, targets: { type: { sel: 'main select' }, date: { sel: 'input[type=date]' }, register: { text: 'Register' } } });
await click(page, 'Register');
await sleep(1000);
await shot(page, 'setup-tax-done', { clip: { x: 256, y: 40, w: 944, h: 300 }, targets: { row: { sel: 'main table tbody tr' } } });

// ---------------------------------------------------------------- fiscal installation
await go(page, '/admin/store-setup/fiscal-installations');
await fill(page, 'Software version', '1.0.0');
await shot(page, 'setup-fiscal-form', {
    clip: { x: 256, y: 40, w: 944, h: 300 },
    targets: { model: { sel: 'main form select' }, version: { input: 'Software version' }, serial: { input: 'Machine serial number (optional)' }, add: { text: 'Add installation' } },
});
await click(page, 'Add installation');
await sleep(1000);
{
    const sel = (await page.$$('main li select'))[0];
    const optionValue = await sel.evaluate((s) => [...s.options].find((o) => o.value)?.value);
    await setValue(page, sel, optionValue);
}
await shot(page, 'setup-fiscal-assign', {
    clip: { x: 256, y: 40, w: 944, h: 360 },
    targets: { card: { sel: 'main li' }, pick: { sel: 'main li select' }, assign: { text: 'Assign', tag: 'button' } },
});
await click(page, 'Assign', { tag: 'button' });
await sleep(1000);
await shot(page, 'setup-fiscal-done', { clip: { x: 256, y: 40, w: 944, h: 360 }, targets: { count: { sel: 'main li span.font-mono' } } });

// ---------------------------------------------------------------- invoice series
await go(page, '/admin/store-setup/invoice-series');
{
    const sel = (await page.$$('main form select'))[0];
    const optionValue = await sel.evaluate((s) => [...s.options].find((o) => o.value)?.value);
    await setValue(page, sel, optionValue);
}
await fill(page, 'Series code', 'MAIN');
await fill(page, 'Prefix', 'INV-');
await fill(page, 'Starting #', '1');
await shot(page, 'setup-series-form', {
    clip: { x: 256, y: 40, w: 944, h: 330 },
    targets: { install: { sel: 'main form select' }, code: { input: 'Series code' }, prefix: { input: 'Prefix' }, start: { input: 'Starting #' }, end: { input: 'Ending # (optional)' }, activate: { text: 'Activate' } },
});
await click(page, 'Activate');
await sleep(1000);
await shot(page, 'setup-series-done', { clip: { x: 256, y: 40, w: 944, h: 330 }, targets: { row: { sel: 'main table tbody tr' } } });

// ---------------------------------------------------------------- inventory locations
await go(page, '/admin/store-setup/inventory-locations');
await fill(page, 'Location name', 'Store shelf');
await shot(page, 'setup-location-form', { clip: { x: 256, y: 40, w: 944, h: 260 }, targets: { name: { input: 'Location name' }, add: { text: 'Add location' } } });
await click(page, 'Add location');
await sleep(1000);
await shot(page, 'setup-location-done', { clip: { x: 256, y: 40, w: 944, h: 260 }, targets: { row: { sel: 'main li, main tbody tr', nth: 0 } } });

// ---------------------------------------------------------------- overview (ready)
await go(page, '/admin/store-setup');
await shot(page, 'setup-overview-ready', { targets: { checks: { sel: 'main section, main ul, main div.space-y-2', nth: 0 } } });

saveAnnotations();
await browser.close();

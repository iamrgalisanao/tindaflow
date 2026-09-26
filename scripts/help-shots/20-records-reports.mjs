import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, api, text, tall, normal, wide, PANEL } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 700) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.querySelector('main') ?? document.body).innerText)).replace(/\n+/g, ' | ').slice(0, n));
await ensureLogin(page, 'marco@tindaflow.test', 'Manager-2026!');

// ---- sales history: filter, then a sale's detail
await go(page, '/admin/sales'); await sleep(1200);
await shot(page, 'sales-list', { targets: { filters: { sel: 'main form, main div.grid', nth: 0 }, table: { sel: 'main table' }, invoice: { input: 'Invoice no.' } } }).catch((e) => console.log('err sales-list', e.message));
await click(page, '000001', { tag: 'td, a, button', exact: false }); await sleep(1500);
await shot(page, 'sales-detail', { targets: { back: { text: 'Sales history', tag: 'a', exact: false, within: 'main' }, invoice: { text: 'Invoice', tag: 'button', exact: true }, refund: { text: 'Refund', tag: 'button', exact: true }, items: { closest: 'section, div.rounded-lg', of: { text: 'Items', tag: 'h3, h2, p, div, span', exact: true } }, payment: { closest: 'section, div.rounded-lg', of: { text: 'Payment', tag: 'h3, h2, p, div, span', exact: true } } } }).catch((e) => console.log('err detail', e.message));
await click(page, 'Invoice', { tag: 'button', exact: true }); await sleep(1500);
await dump('invoice panel', 600);
await shot(page, 'sales-invoice-panel', { clip: PANEL, targets: { reprint: { text: 'Print', exact: false, tag: 'button', within: '[role=dialog]' } } }).catch((e) => console.log('err invoice', e.message));
await page.keyboard.press('Escape'); await sleep(400);

// ---- reports
await go(page, '/admin/reports'); await sleep(1500);
await shot(page, 'reports-hub', { targets: { tabs: { text: 'Sales Reports', tag: 'button', exact: true }, card: { closest: 'a, div.rounded-lg', of: { text: 'Daily Sales Summary', tag: 'h3, h4, p, span, div', within: 'main' } } } }).catch((e) => console.log('err hub', e.message));
await go(page, '/admin/reports/daily-sales-summary'); await sleep(1500);
await shot(page, 'reports-viewer', { targets: { presets: { sel: '[role=group][aria-label="Date presets"]' }, from: { input: 'From' }, apply: { text: 'Apply', tag: 'button' }, export: { text: 'Export CSV', tag: 'button' }, cards: { sel: 'main div.mb-3.grid', nth: 0 }, table: { sel: 'main table' } } }).catch((e) => console.log('err viewer', e.message));
await go(page, '/admin/reports/low-stock'); await sleep(1500);
await shot(page, 'reports-low-stock', { targets: { table: { sel: 'main table' } } }).catch((e) => console.log('err low', e.message));

// ---- records
await go(page, '/admin/shifts'); await sleep(1200);
await wide(page, 1440);
await shot(page, 'records-shifts', { targets: { table: { sel: 'main table' } } });
await normal(page);
await click(page, 'CLOSED', { tag: 'td, a, button, span', exact: false, nth: 0 }).catch(() => console.log('no row click')); await sleep(1500);
console.log(page.url());
await go(page, '/admin/fiscal-days'); await sleep(1200);
await shot(page, 'records-fiscal-days', { targets: { table: { sel: 'main table, main ul', nth: 0 } } });
await page.evaluate(() => { const a = document.querySelector('main a[href*="fiscal-days/"]'); if (a) a.click(); else document.querySelector('main tbody tr')?.click(); });
await sleep(1500);
console.log(page.url()); await dump('fiscal day', 700);
await shot(page, 'records-z-reading', { targets: { z: { text: 'Z-reading', exact: false, tag: 'h2, h3' } } }).catch((e) => console.log('err z', e.message));
await go(page, '/admin/audit'); await sleep(1200);
await shot(page, 'records-audit', { targets: { filter: { sel: 'main select', nth: 0 }, rows: { sel: 'main ul, main table', nth: 0 } } }).catch((e) => console.log('err audit', e.message));
await go(page, '/admin/journal'); await sleep(1200);
await shot(page, 'records-journal', { targets: { export: { text: 'Export CSV', tag: 'button' }, type: { sel: 'main select', nth: 0 } } }).catch((e) => console.log('err journal', e.message));
saveAnnotations();
await browser.close();

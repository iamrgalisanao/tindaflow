import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, pick, api, text, tall, normal, PANEL } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 900) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, n));
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
await go(page, '/admin/store-setup/inventory-locations');
if (!(await text(page)).includes('Backroom')) { await fill(page, 'Location name', 'Backroom'); await click(page, 'Add location'); await sleep(1000); }

// ---- transfers
await go(page, '/admin/inventory/transfers'); await sleep(1000);
await shot(page, 'transfers-empty', { targets: { move: { text: 'Move stock', tag: 'button' } } });
await click(page, 'Move stock', { tag: 'button' }); await sleep(800);
await pick(page, await page.$('#transfer_from'), 'Store shelf');
await pick(page, await page.$('#transfer_to'), 'Backroom');
await fill(page, '#transfer_product', 'Bottled'); await sleep(700);
await click(page, 'Bottled Water', { tag: 'button, li, [role=option]', exact: false, within: '[role=dialog]' }); await sleep(600);
await page.keyboard.type('12');
await click(page, 'Add', { tag: 'button', within: '[role=dialog]' }); await sleep(600);
await fill(page, '#transfer_note', 'Restock the backroom shelf');
await dump('transfer with line');
await shot(page, 'transfers-form', { clip: PANEL, targets: { from: { sel: '#transfer_from' }, to: { sel: '#transfer_to' }, product: { sel: '#transfer_product' }, note: { sel: '#transfer_note' }, move: { text: 'Move stock', tag: 'button', within: '[role=dialog]' } } }).catch((e) => console.log('err', e.message));
await click(page, 'Move stock', { tag: 'button', within: '[role=dialog]' });
await sleep(1500);
await dump('after transfer', 500);
await shot(page, 'transfers-done', { targets: { row: { sel: 'main li, main tbody tr', nth: 0 } } }).catch((e) => console.log('err', e.message));

// ---- stock count
await go(page, '/admin/inventory/counts'); await sleep(1000);
await shot(page, 'counts-list', { targets: { start: { text: 'Start a count', tag: 'button' }, filter: { text: 'In progress', tag: 'button' } } });
saveAnnotations();
await browser.close();

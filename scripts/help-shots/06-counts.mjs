import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, pick, api, text, tall, normal, PANEL } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 1100) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=alertdialog]') ?? document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, n));
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
await go(page, '/admin/inventory/counts'); await sleep(1000);
const existing = await page.evaluate(() => [...document.querySelectorAll('main a[href*="/counts/"]')].map((a) => a.href)[0]);
if (existing) { await page.goto(existing, { waitUntil: 'networkidle0' }); }
else {
  await shot(page, 'counts-list', { targets: { start: { text: 'Start a count', tag: 'button' }, filter: { text: 'In progress', tag: 'button' } } });
  await click(page, 'Start a count', { tag: 'button' }); await sleep(700);
  await fill(page, '[role=dialog] input[type=text]', 'Aisle 2, month-end count');
  await shot(page, 'counts-start-form', { clip: PANEL, targets: { location: { sel: '[role=dialog] select' }, note: { sel: '[role=dialog] input[type=text]' }, start: { text: 'Start count', tag: 'button', within: '[role=dialog]' } } });
  await click(page, 'Start count', { tag: 'button', within: '[role=dialog]' }); await sleep(1500);
}
await sleep(800);
const search = 'input[placeholder="Search by name, SKU or barcode"]';
await shot(page, 'counts-detail-empty', { targets: { search: { sel: search }, post: { text: 'Post count', tag: 'button' }, discard: { text: 'Discard count', tag: 'button' } } });
for (const [name, qty] of [['Corn Chips', '27'], ['Bar Soap', '3'], ['Instant Coffee', '12']]) {
  await fill(page, search, name); await sleep(700);
  if (name === 'Corn Chips') await shot(page, 'counts-search', { targets: { search: { sel: search }, first: { sel: 'main [role=option], main ul li button, main ul li', nth: 0 } } }).catch((e) => console.log('err', e.message));
  await click(page, name, { tag: 'button, li, [role=option]', exact: false }); await sleep(700);
  await page.keyboard.type(qty);
  await page.keyboard.press('Enter'); await sleep(900);
}
await fill(page, search, '').catch(() => {});
await shot(page, 'counts-lines', { targets: { summary: { text: 'products counted', exact: false }, table: { sel: 'main table' }, found: { sel: 'main table input', nth: 0 }, diff: { text: 'Difference', tag: 'th' }, post: { text: 'Post count', tag: 'button' } } });
await click(page, 'Post count', { tag: 'button' }); await sleep(1500);
await shot(page, 'counts-post-confirm', { clip: { x: 250, y: 200, w: 700, h: 360 }, targets: { dialog: { sel: '[role=alertdialog] > div.relative' }, cancel: { text: 'Cancel', tag: 'button', within: '[role=alertdialog]' }, post: { text: 'Post count', tag: 'button', within: '[role=alertdialog]' } } });
await click(page, 'Post count', { tag: 'button', within: '[role=alertdialog]' }); await sleep(1800);
await dump('posted', 700);
await shot(page, 'counts-posted', { targets: { status: { text: 'Posted', exact: false, tag: 'span' }, table: { sel: 'main table' } } }).catch((e) => console.log('err posted', e.message));
saveAnnotations();
await browser.close();

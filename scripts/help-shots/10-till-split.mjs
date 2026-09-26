import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, text, tall, normal } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l) => console.log(l, '::', (await page.evaluate(() => document.body.innerText)).replace(/\n+/g, ' | ').slice(0, 700));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');
await go(page, '/pos');
const tile = (name) => click(page, name, { tag: 'button', exact: false });

// ---------------------------------------------------------------- split payment (GCash + cash)
await tile('Cooking Oil (1L)'); await tile('Bar Soap'); await tile('Soy Sauce (350ml)');
await click(page, 'Charge', { exact: false, tag: 'button' });
await sleep(800);
await tall(page, 940);
await click(page, 'GCash', { tag: 'button' });
await sleep(300);
await fill(page, '#payment_amount', '40.00');
await sleep(300);
await shot(page, 'till-split-1', { targets: { gcash: { text: 'GCash', tag: 'button' }, amount: { sel: '#payment_amount' }, split: { text: 'Split payment', tag: 'button' } } });
await click(page, 'Split payment', { tag: 'button' });
await sleep(500);
await dump('split');
await shot(page, 'till-split-2', { targets: { rows: { sel: 'ul[aria-label="Payment rows"]' }, cash: { text: 'Cash', tag: 'button', nth: 0 }, complete: { text: 'Complete sale', exact: false, tag: 'button' } } });
await click(page, 'Complete sale', { exact: false, tag: 'button' });
await sleep(1800);
await normal(page);
await dump('split-receipt');
await click(page, 'New sale', { tag: 'button' });
await sleep(1500);


saveAnnotations();
await browser.close();

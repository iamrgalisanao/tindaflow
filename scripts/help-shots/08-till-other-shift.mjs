import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, text } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l) => console.log(l, '::', (await page.evaluate(() => document.body.innerText)).replace(/\n+/g, ' | ').slice(0, 500));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');

// ---- Liza arrives and the admin's shift from earlier is still open on this till
await go(page, '/pos');
await dump('pos');
await shot(page, 'till-other-shift', { clip: { x: 300, y: 10, w: 600, h: 400 }, targets: { message: { sel: 'p.text-amber-300' }, close: { text: 'Count the drawer and close it' }, back: { text: 'Back to dashboard' } } });
await click(page, 'Count the drawer and close it');
await sleep(500);
await shot(page, 'till-close-form', { clip: { x: 300, y: 40, w: 600, h: 400 }, targets: { note: { sel: 'form p.text-xs' }, counted: { sel: '#declared_cash' }, back: { text: 'Back', tag: 'button' }, close: { text: 'Close shift', tag: 'button' } } });
await fill(page, '#declared_cash', '0.00');
await click(page, 'Close shift', { tag: 'button' });
await sleep(1200);
await dump('after-close');
await shot(page, 'till-shift-summary-other', { clip: { x: 300, y: 40, w: 600, h: 400 }, targets: { expected: { text: 'Expected cash', tag: 'dt' }, variance: { text: 'Variance', tag: 'dt' } } });
await click(page, 'Back to dashboard');
await sleep(800);


saveAnnotations();
await browser.close();

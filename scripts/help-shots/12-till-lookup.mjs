import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, text, PANEL } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.body).innerText)).replace(/\n+/g, ' | ').slice(0, 900));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');
await go(page, '/pos');
await sleep(1200);
await click(page, 'Lookup', { tag: 'button' });
await sleep(1500);
await shot(page, 'till-lookup', { targets: { tabs: { sel: 'nav[aria-label="Till"]' }, search: { sel: 'input[aria-label="Search by transaction or invoice number"]' }, list: { sel: 'main ul, ul', nth: 0 } } });
// open sale 000003 (the Senior Citizen sale)
await click(page, '000003', { tag: 'button', exact: false });
await sleep(1200);
await shot(page, 'till-lookup-detail', { targets: { row: { text: '000003', tag: 'button', exact: false }, receipt: { text: 'Receipt', tag: 'button' }, void: { text: 'Request void', tag: 'button' }, refund: { text: 'Request refund', tag: 'button' } } });
saveAnnotations();
await browser.close();

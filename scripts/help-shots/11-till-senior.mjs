import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, text, tall, normal } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l) => console.log(l, '::', (await page.evaluate(() => document.body.innerText)).replace(/\n+/g, ' | ').slice(0, 700));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');
await go(page, '/pos');
await sleep(1500);
const tile = (name) => click(page, name, { tag: 'button', exact: false });

// ---------------------------------------------------------------- Senior Citizen / PWD discount
await tile('Instant Noodles'); await tile('Rice (1kg)');
await click(page, 'Senior Citizen / PWD discount', { tag: 'label', exact: false });
await sleep(500);
await fill(page, 'input[aria-label="Senior Citizen or PWD ID number"]', 'OSCA-2026-000123');
await fill(page, 'input[aria-label="Senior Citizen or PWD name"]', 'Lolo Ben Mercado');
await sleep(400);
await dump('sc');
await shot(page, 'till-discount-sc', {
  targets: {
    check: { text: 'Senior Citizen / PWD discount', tag: 'label', exact: false }, type: { sel: '[role=radiogroup][aria-label="Discount type"]' }, id: { sel: 'input[aria-label="Senior Citizen or PWD ID number"]' },
    name: { sel: 'input[aria-label="Senior Citizen or PWD name"]' }, rule: { sel: '[role=radiogroup][aria-label="Discount rule"]' }, charge: { text: 'Charge', exact: false, tag: 'button' },
  },
});
await click(page, 'Charge', { exact: false, tag: 'button' });
await sleep(900);
await tall(page, 940);
await click(page, 'Exact', { tag: 'button' });
await sleep(300);
await click(page, 'Complete sale', { exact: false, tag: 'button' });
await sleep(1800);
await normal(page);
await dump('sc-receipt');
await shot(page, 'till-receipt-discount', { clip: { x: 300, y: 60, w: 600, h: 520 }, targets: { numbers: { sel: 'dl' }, new: { text: 'New sale', tag: 'button' } } });
await click(page, 'New sale', { tag: 'button' });

saveAnnotations();
await browser.close();

import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, text, tall, normal } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l) => console.log(l, '::', (await page.evaluate(() => document.body.innerText)).replace(/\n+/g, ' | ').slice(0, 500));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');

// ---- Open your own shift
await go(page, '/pos');
if (await page.$('#opening_cash')) {
await dump('open-shift');
await shot(page, 'till-open-shift', { targets: { cash: { sel: '#opening_cash' }, open: { text: 'Open shift', tag: 'button' } } });
await fill(page, '#opening_cash', '500.00');
await click(page, 'Open shift', { tag: 'button' });
await sleep(1500);
}
await dump('register');
await shot(page, 'till-register-empty', {
  targets: {
    tabs: { sel: 'nav[aria-label="Till"]' }, search: { sel: 'input[aria-label="Scan a barcode or search products"]' },
    products: { sel: 'section[aria-label="Products"] ul.grid' }, cart: { sel: 'section[aria-label="Cart"]' }, header: { sel: 'header' },
  },
});

// ---- Add products: tap a tile, search by name, scan a barcode
await click(page, 'Sugar (1kg)', { tag: 'button', exact: false });
await sleep(500);
const search = 'input[aria-label="Scan a barcode or search products"]';
await fill(page, search, 'coffee');
await page.keyboard.press('Enter');
await sleep(1200);
await shot(page, 'till-search-results', { targets: { search: { sel: search }, results: { sel: 'section[aria-label="Products"] ul.grid' }, back: { text: 'Back to all products' } } });
await click(page, 'Instant Coffee (sachet)', { tag: 'button', exact: false });
await sleep(500);
await click(page, 'Back to all products');
await fill(page, search, '4800000000042');
await page.keyboard.press('Enter');
await sleep(1200);
await shot(page, 'till-scan-added', { targets: { search: { sel: search }, notice: { sel: '[role=status]' }, cart: { sel: 'section[aria-label="Cart"]' } } });
await dump('cart');
await fill(page, 'input[aria-label="Quantity of Instant Coffee (sachet)"]', '3');
await sleep(500);
await shot(page, 'till-cart', {
  targets: {
    qty: { sel: 'input[aria-label="Quantity of Instant Coffee (sachet)"]', }, more: { sel: 'button[aria-label="One more Instant Coffee (sachet)"]' }, less: { sel: 'button[aria-label="One less Instant Coffee (sachet)"]' },
    remove: { sel: 'button[aria-label="Remove Corn Chips (60g)"]' }, total: { sel: '[aria-label="Total to charge"]' }, charge: { text: 'Charge', exact: false, tag: 'button' }, cart: { sel: 'section[aria-label="Cart"]' },
  },
});
await click(page, 'Charge', { exact: false, tag: 'button' });
await sleep(900);
await tall(page, 940);
await dump('tender');
await shot(page, 'till-tender', {
  targets: { methods: { sel: '[role=radiogroup][aria-label="Payment method"]' }, amount: { sel: '#payment_amount' }, quick: { text: 'Exact', tag: 'button' }, summary: { sel: 'section[aria-label="Order summary"]' }, complete: { text: 'Complete sale', exact: false, tag: 'button' }, back: { text: 'Back to cart' } },
});
await click(page, '₱200', { tag: 'button' });
await sleep(500);
await shot(page, 'till-tender-change', { targets: { quick200: { text: '₱200', tag: 'button' }, change: { sel: '[aria-live=polite]' }, complete: { text: 'Complete sale', exact: false, tag: 'button' } } });
await click(page, 'Complete sale', { exact: false, tag: 'button' });
await sleep(1800);
await normal(page);
await dump('receipt');
await shot(page, 'till-receipt', { clip: { x: 300, y: 60, w: 600, h: 520 }, targets: { numbers: { sel: 'dl' }, total: { text: 'Grand total', tag: 'dt' }, change: { text: 'Change', tag: 'dt' }, print: { text: 'Print invoice', tag: 'button' }, new: { text: 'New sale', tag: 'button' } } });
await click(page, 'New sale', { tag: 'button' });
await sleep(800);

saveAnnotations();
await browser.close();

import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, pick, PANEL, scrollPanel, text, tall, normal } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');

await go(page, '/admin/inventory/stock');
await shot(page, 'stock-empty', { targets: { receive: { text: 'Receive stock', tag: 'button' }, adjust: { text: 'Adjust stock', tag: 'button' }, nav: { sel: 'nav[aria-label="Back office"]' } } });

const receive = async (name, qty, cost, type, { shoot = false } = {}) => {
  await go(page, '/admin/inventory/stock');
  await click(page, 'Receive stock', { tag: 'button' });
  await sleep(500);
  await fill(page, '#stock_product', name);
  await sleep(800);
  if (shoot) await shot(page, 'stock-receive-search', { clip: PANEL, targets: { product: { sel: '#stock_product' }, first: { sel: '[role=dialog] [role=option], [role=dialog] ul li button, [role=dialog] ul li', nth: 0 } } });
  await click(page, name, { tag: 'button, li, [role=option]', exact: false, within: '[role=dialog]' });
  await sleep(400);
  if (type === 'Opening stock') await click(page, 'Opening stock', { tag: 'label', exact: false });
  await fill(page, '#stock_quantity', String(qty));
  await fill(page, '#stock_unit_cost', String(cost));
  if (shoot) {
    await fill(page, '#stock_note', 'Delivery from Ramos Trading, receipt no. 1042');
    await shot(page, 'stock-receive-form', { clip: PANEL, targets: { product: { closest: 'div.rounded-md', of: { text: name + ' (1kg)', tag: 'p', exact: false } }, type: { text: 'Type', exact: false, tag: 'legend' }, qty: { sel: '#stock_quantity' }, cost: { sel: '#stock_unit_cost' }, note: { sel: '#stock_note' }, record: { text: 'Record receipt' } } });
  }
  await click(page, 'Record receipt');
  await sleep(1300);
};
await receive('Sugar', 40, 58, 'Purchase receipt', { shoot: true });
for (const [n, q, c] of [['Soy Sauce', 24, 16], ['Instant Coffee', 12, 3.5], ['Corn Chips', 30, 14], ['Bar Soap', 3, 18], ['Bottled Water', 48, 11], ['Canned Sardines', 36, 13], ['Cooking Oil', 20, 14], ['Instant Noodles', 60, 12], ['Rice', 25, 10]]) await receive(n, q, c, 'Opening stock');

await go(page, '/admin/inventory/stock');
await shot(page, 'stock-list', { targets: { table: { sel: 'main table' }, filter: { text: 'Low stock', tag: 'button' } } });
await click(page, 'Low stock', { tag: 'button' });
await sleep(900);
await shot(page, 'stock-low', { targets: { filter: { text: 'Low stock', tag: 'button' }, table: { sel: 'main table' } } });

// adjustment: damaged goods
await go(page, '/admin/inventory/stock');
await click(page, 'Adjust stock', { tag: 'button' });
await sleep(500);
await fill(page, '#stock_product', 'Bottled Water');
await sleep(800);
await click(page, 'Bottled Water', { tag: 'button, li, [role=option]', exact: false, within: '[role=dialog]' });
await click(page, 'Damaged', { tag: 'label', exact: false });
await fill(page, '#stock_quantity', '2');
await fill(page, '#stock_reason', 'Two bottles crushed when the crate fell.');
await tall(page, 900);
await shot(page, 'stock-adjust-form', { clip: { x: 720, y: 0, w: 480, h: 900 }, targets: { product: { closest: 'div.rounded-md', of: { text: 'Bottled Water', tag: 'p', exact: false } }, kind: { text: 'What happened', exact: false, tag: 'legend' }, qty: { sel: '#stock_quantity' }, reason: { sel: '#stock_reason' }, record: { text: 'Record adjustment' } } });
await click(page, 'Record adjustment');
await sleep(1300);
await normal(page);

await go(page, '/admin/inventory/movements');
await shot(page, 'stock-movements', { targets: { filters: { sel: 'main select', nth: 0 }, table: { sel: 'main table' } } });

saveAnnotations();
await browser.close();

import { launch, ensureLogin, go, click, fill, input, shot, saveAnnotations, sleep, find, setValue, pick, PANEL, scrollPanel, text } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
const log = async (l) => console.log(l, (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, 300));

// ================================================================= USERS
await go(page, '/admin/users');
await shot(page, 'users-list', { targets: { 'new-user': { text: 'New user' }, filters: { sel: 'main select', nth: 0 }, table: { sel: 'main table' }, edit: { text: 'Edit', nth: 0 }, deactivate: { text: 'Deactivate', nth: 0 } } });
await click(page, 'New user');
await sleep(600);
await fill(page, '#user_name', 'Marco Reyes');
await fill(page, '#user_email', 'marco@tindaflow.test');
await shot(page, 'users-form-top', { clip: PANEL, targets: { name: { sel: '#user_name' }, email: { sel: '#user_email' } } });
// role: pick the MANAGER card
await click(page, 'MANAGER', { exact: false, tag: 'label' });
await fill(page, '#user_password', 'Manager-2026!');
await sleep(300);
await scrollPanel(page, '#user_form');
await shot(page, 'users-form-bottom', { clip: PANEL, targets: { role: { text: 'MANAGER', exact: false, tag: 'label' }, password: { sel: '#user_password' }, generate: { text: 'Generate' }, create: { text: 'Create user' } } });
await click(page, 'Create user');
await sleep(1200);
await click(page, 'New user');
await sleep(600);
await fill(page, '#user_name', 'Liza Santos');
await fill(page, '#user_email', 'liza@tindaflow.test');
await click(page, 'CASHIER', { exact: false, tag: 'label' });
await fill(page, '#user_password', 'Cashier-2026!');
await click(page, 'Create user');
await sleep(1200);
await go(page, '/admin/users');
await shot(page, 'users-created', { targets: { table: { sel: 'main table' } } });

// ================================================================= CATEGORIES + BRANDS
await go(page, '/admin/catalog/categories');
for (const name of ['Groceries', 'Beverages', 'Snacks', 'Household']) {
  await fill(page, 'Category name', name);
  if (name === 'Groceries') await shot(page, 'catalog-category-form', { clip: { x: 256, y: 40, w: 944, h: 330 }, targets: { name: { input: 'Category name' }, add: { text: 'Add category' } } });
  await click(page, 'Add category');
  await sleep(700);
}
await shot(page, 'catalog-categories-done', { clip: { x: 256, y: 40, w: 944, h: 430 }, targets: { list: { sel: 'main table, main ul', nth: 0 } } });
await go(page, '/admin/catalog/brands');
for (const name of ["Nena's Choice", 'Pinoy Best', 'Sunrise']) {
  await fill(page, 'Brand name', name);
  await click(page, 'Add brand');
  await sleep(700);
}

// ================================================================= PRODUCTS
await go(page, '/admin/catalog/products');
await shot(page, 'catalog-products-list', { targets: { 'new-product': { text: 'New product' }, import: { text: 'Import CSV' }, export: { text: 'Export CSV' }, search: { input: 'Search' }, table: { sel: 'main table' } } });

const newProduct = async (p, { shot: shoot = false } = {}) => {
  await go(page, '/admin/catalog/products');
  await click(page, 'New product');
  await sleep(700);
  await fill(page, '#product_sku', p.sku);
  if (p.barcode) await fill(page, '#product_barcode', p.barcode);
  await fill(page, '#product_name', p.name);
  if (p.category) await pick(page, await page.$('#product_category'), p.category);
  if (p.brand) await pick(page, await page.$('#product_brand'), p.brand);
  await fill(page, '#product_unit', p.unit ?? 'pc');
  await fill(page, '#product_cost', p.cost);
  await fill(page, '#product_price', p.price);
  if (p.tax && p.tax !== 'VATABLE') await click(page, p.tax, { tag: 'span', exact: false });
  if (p.reorder !== undefined) await fill(page, '#product_reorder', String(p.reorder));
  if (shoot) {
    await scrollPanel(page, '#product_form', 'top');
    await shot(page, 'catalog-product-form-top', { clip: PANEL, targets: { sku: { sel: '#product_sku' }, barcode: { sel: '#product_barcode' }, name: { sel: '#product_name' }, category: { sel: '#product_category' }, brand: { sel: '#product_brand' } } });
    await scrollPanel(page, '#product_form', 'bottom');
    await shot(page, 'catalog-product-form-bottom', { clip: PANEL, targets: { unit: { sel: '#product_unit' }, cost: { sel: '#product_cost' }, price: { sel: '#product_price' }, tax: { text: 'Tax class', exact: false, tag: 'legend' }, track: { sel: '#product_form input[type=checkbox]' }, reorder: { sel: '#product_reorder' }, create: { text: 'Create product' } } });
  }
  await click(page, 'Create product');
  await sleep(1200);
};
const products = [
  { sku: 'GRO-001', barcode: '4800000000011', name: 'Sugar (1kg)', category: 'Groceries', brand: "Nena's Choice", unit: 'kg', cost: '58', price: '72.00', reorder: 5, shoot: true },
  { sku: 'GRO-002', barcode: '4800000000028', name: 'Soy Sauce (350ml)', category: 'Groceries', brand: 'Pinoy Best', unit: 'pc', cost: '16', price: '22.00', reorder: 6 },
  { sku: 'BEV-001', barcode: '4800000000035', name: 'Instant Coffee (sachet)', category: 'Beverages', brand: 'Sunrise', unit: 'pc', cost: '3.50', price: '5.00', reorder: 20 },
  { sku: 'SNK-001', barcode: '4800000000042', name: 'Corn Chips (60g)', category: 'Snacks', brand: 'Pinoy Best', unit: 'pack', cost: '14', price: '20.00', reorder: 10 },
  { sku: 'HOU-001', barcode: '4800000000059', name: 'Bar Soap', category: 'Household', brand: "Nena's Choice", unit: 'pc', cost: '18', price: '25.00', reorder: 8 },
];
for (const p of products) await newProduct(p, { shot: p.shoot });
await go(page, '/admin/catalog/products');
await shot(page, 'catalog-products-done', { targets: { table: { sel: 'main table' } } });

saveAnnotations();
await browser.close();

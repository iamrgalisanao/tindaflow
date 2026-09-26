import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, pick, api, text, tall, normal, PANEL, scrollPanel } from './lib.mjs';
import path from 'node:path';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 1100) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=alertdialog]') ?? document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, n));
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');

// ---- import a CSV
await go(page, '/admin/catalog/products'); await sleep(1000);
await click(page, 'Import CSV', { tag: 'button' }); await sleep(800);
const file = await page.$('[role=dialog] input[type=file]');
await file.uploadFile(new URL('./products.csv', import.meta.url).pathname);
await sleep(1800);
await dump('import preview');
await shot(page, 'import-preview', { clip: PANEL, targets: { download: { text: 'Download current catalog', exact: false, tag: 'button, a' }, file: { sel: '[role=dialog] input[type=file]' }, summary: { sel: '[role=dialog] dl' }, import: { text: 'Import 3 products', tag: 'button', within: '[role=dialog]' } } }).catch((e) => console.log('err', e.message));
await click(page, 'Import 3 products', { tag: 'button', within: '[role=dialog]' }); await sleep(1800);
await dump('import done', 500);
await shot(page, 'import-done', { clip: PANEL, targets: { summary: { sel: '[role=dialog] dl' } } }).catch((e) => console.log('err', e.message));
await go(page, '/admin/catalog/products'); await sleep(1000);

// ---- packs on a product
await fill(page, 'Search', 'Bottled Water'); await sleep(1200);
await click(page, 'Edit', { tag: 'button, a', nth: 0 }); await sleep(1000);
await fill(page, 'input[aria-label="Pack name"]', 'Case');
await fill(page, 'input[aria-label="Units per pack"]', '24');
await fill(page, 'input[aria-label="Barcode"]', '4800000000110');
await shot(page, 'packs-form', { clip: PANEL, targets: { name: { sel: 'input[aria-label="Pack name"]' }, units: { sel: 'input[aria-label="Units per pack"]' }, barcode: { sel: 'input[aria-label="Barcode"]' }, add: { text: 'Add', tag: 'button', within: '[role=dialog]', exact: true } } }).catch((e) => console.log('err', e.message));
await click(page, 'Add', { tag: 'button', within: '[role=dialog]', exact: true }); await sleep(1200);
await dump('after pack', 600);
await shot(page, 'packs-done', { clip: PANEL, targets: { list: { sel: 'ul[aria-label="Packs and other barcodes"]' } } }).catch((e) => console.log('err', e.message));
await scrollPanel(page, '#product_form', 'bottom');
await shot(page, 'catalog-edit-bottom', { clip: PANEL, targets: { price: { sel: '#product_price' }, deactivate: { text: 'Deactivate product', tag: 'button' }, save: { text: 'Save changes', tag: 'button' } } }).catch((e) => console.log('err', e.message));
saveAnnotations();
await browser.close();

import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, find, setValue, pick, api, text, tall, normal, PANEL, scrollPanel, logout } from './lib.mjs';
const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 900) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=alertdialog]') ?? document.querySelector('[role=dialog]') ?? document.querySelector('main') ?? document.body).innerText)).replace(/\n+/g, ' | ').slice(0, n));

// ---- wrong password (signed out)
await go(page, '/login').catch(() => {});
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');

// ---- receive by the pack
await go(page, '/admin/inventory/stock'); await sleep(1000);
await click(page, 'Receive stock', { tag: 'button' }); await sleep(600);
await fill(page, '#stock_product', 'Bottled Water'); await sleep(800);
await click(page, 'Bottled Water', { tag: 'button, li, [role=option]', exact: false, within: '[role=dialog]' }); await sleep(700);
await click(page, 'Case', { tag: 'label', exact: false });
await fill(page, '#stock_quantity', '2');
await sleep(400);
await shot(page, 'stock-receive-pack', { clip: PANEL, targets: { pack: { text: 'Received as', exact: false, tag: 'legend, p, label, div' }, case: { text: 'Case', tag: 'label', exact: false }, qty: { sel: '#stock_quantity' } } });
await click(page, 'Cancel', { tag: 'button', within: '[role=dialog]' }); await sleep(500);

// ---- users: edit and deactivate
await go(page, '/admin/users'); await sleep(1200);
await click(page, 'Edit', { tag: 'button, a', nth: 2 }); await sleep(900);
await shot(page, 'users-edit', { clip: PANEL, targets: { role: { text: 'Role', exact: false, tag: 'legend' }, password: { sel: '#user_password' }, access: { text: 'Current access', exact: false, tag: 'p' }, save: { text: 'Save changes', tag: 'button' } } });
await click(page, 'Cancel', { tag: 'button', within: '[role=dialog]' }); await sleep(500);
await click(page, 'Deactivate', { tag: 'button', nth: 0 }); await sleep(700);
await dump('deactivate user');
await shot(page, 'users-deactivate', { clip: { x: 250, y: 200, w: 700, h: 360 }, targets: { dialog: { sel: '[role=alertdialog] > div.relative' }, cancel: { text: 'Cancel', tag: 'button', within: '[role=alertdialog]' }, confirm: { text: 'Deactivate', tag: 'button', within: '[role=alertdialog]' } } });
await click(page, 'Cancel', { tag: 'button', within: '[role=alertdialog]' }); await sleep(400);

// ---- retire a product
await go(page, '/admin/catalog/products'); await sleep(1000);
await click(page, 'Deactivate', { tag: 'button', nth: 0 }); await sleep(700);
await dump('deactivate product');
await shot(page, 'catalog-deactivate', { clip: { x: 250, y: 200, w: 700, h: 360 }, targets: { dialog: { sel: '[role=alertdialog] > div.relative' }, cancel: { text: 'Cancel', tag: 'button', within: '[role=alertdialog]' }, confirm: { text: 'Deactivate', tag: 'button', within: '[role=alertdialog]' } } });
await click(page, 'Cancel', { tag: 'button', within: '[role=alertdialog]' }); await sleep(400);

// ---- sign in failure (signed out)
await logout(page);
await go(page, '/login');
await fill(page, '#email', 'liza@tindaflow.test');
await fill(page, '#password', 'not-my-password');
await click(page, 'Sign in', { tag: 'button' }); await sleep(2500);
await dump('login error', 400);
await shot(page, 'signin-error', { clip: { x: 300, y: 190, w: 600, h: 440 }, targets: { message: { sel: 'p.text-rose-400, [role=alert]', nth: 0 }, email: { sel: '#email' }, password: { sel: '#password' }, submit: { sel: 'button[type=submit]' } } }).catch((e) => console.log('err', e.message));
saveAnnotations();
await browser.close();

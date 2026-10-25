/**
 * Canteen help pictures, part 1: the Menu groups screen and putting a product in a group (guide "Set up the menu groups").
 *
 * Runs after the retail pictures (00-21), on the same scratch store. It deliberately comes last: once menu groups exist the
 * till shows group tabs instead of category tabs, so every retail picture taken before this script stays a true picture of a
 * store that has no groups. The first groups are built on screen (so the empty state and the forms can be shown); the rest of
 * the canteen tree is then filled in by MenuGroupSeeder, which leaves a group that already exists exactly as it is.
 */
import { seed } from './canteen.mjs';
import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, pick, api, PANEL, tall, normal } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
const dump = async (l, n = 600) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, n));
const addGroup = async (name) => {
    await click(page, 'Add group', { tag: 'button' });
    await fill(page, 'New group name', name);
    await click(page, 'Add group', { tag: 'button' });
    await sleep(900);
};

// ---------------------------------------------------------------- the empty screen, then the first group
await go(page, '/admin/catalog/menu-groups');
await shot(page, 'groups-empty', { clip: { x: 256, y: 40, w: 944, h: 330 }, targets: { add: { text: 'Add group', tag: 'button' }, empty: { text: 'No menu groups yet', exact: false, tag: 'div' } } });
await click(page, 'Add group', { tag: 'button' });
await fill(page, 'New group name', 'Breakfast');
await shot(page, 'groups-add-form', { clip: { x: 256, y: 40, w: 944, h: 330 }, targets: { name: { input: 'New group name' }, add: { text: 'Add group', tag: 'button' } } });
await click(page, 'Add group', { tag: 'button' });
await sleep(900);
await addGroup('Lunch');
await dump('after two groups');

// ---------------------------------------------------------------- a sub-group under Lunch
const subForm = 'ul[aria-label="Menu groups"] form button[type=submit]';
await click(page, 'Add sub-group', { tag: 'button', nth: 1 });
await fill(page, 'New sub-group of Lunch', 'Standard Meal');
await shot(page, 'groups-add-sub', { clip: { x: 256, y: 40, w: 944, h: 360 }, targets: { name: { input: 'New sub-group of Lunch' }, add: { sel: subForm } } });
await (await page.$(subForm)).click();
await sleep(900);
await click(page, 'Add sub-group', { tag: 'button', nth: 1 });
await fill(page, 'New sub-group of Lunch', 'Combo Meal');
await (await page.$(subForm)).click();
await sleep(900);

// ---------------------------------------------------------------- the rest of the canteen tree, then rename / reorder / hide
seed('MenuGroupSeeder');
await go(page, '/admin/catalog/menu-groups');
await dump('tree', 900);
await tall(page, 900);
await shot(page, 'groups-tree', {
    targets: {
        list: { sel: 'ul[aria-label="Menu groups"]' },
        up: { sel: 'button[aria-label="Move Beverages up"]' },
        down: { sel: 'button[aria-label="Move Beverages down"]' },
        rename: { text: 'Rename', tag: 'button', nth: 9 },
        hide: { text: 'Hide', tag: 'button', nth: 9 },
        subgroups: { union: [{ text: 'Standard Meal', tag: 'span', exact: false }, { text: 'Combo Meal', tag: 'span', exact: false }] },
    },
});
await click(page, 'Hide', { tag: 'button', nth: 10 });
await sleep(1000);
await shot(page, 'groups-hidden', {
    targets: { row: { closest: 'li', of: { text: 'Coffeeshop', tag: 'span' } }, badge: { text: 'INACTIVE', tag: 'span' }, show: { text: 'Show', tag: 'button', nth: 0 } },
});
await click(page, 'Show', { tag: 'button', nth: 0 });
await sleep(1000);
await normal(page);

// ---------------------------------------------------------------- put a product in a group (edit form), then place a few more
await go(page, '/admin/catalog/products');
await fill(page, 'Search', 'Bottled Water');
await sleep(1200);
await click(page, 'Edit', { tag: 'button, a', nth: 0 });
await sleep(1000);
const pickGroup = async (label) => pick(page, await page.$('#product_menu_group'), label);
await pickGroup('Beverages');
await page.$eval('#product_menu_group', (sel) => {
    for (let n = sel.parentElement; n; n = n.parentElement) {
        if (n.scrollHeight > n.clientHeight + 5 && /(auto|scroll)/.test(getComputedStyle(n).overflowY)) {
            n.scrollTo({ top: sel.getBoundingClientRect().top - n.getBoundingClientRect().top + n.scrollTop - 120, behavior: 'instant' });
            return;
        }
    }
});
await sleep(300);
await shot(page, 'groups-product-form', { clip: PANEL, beyond: false, targets: { group: { sel: '#product_menu_group' }, save: { text: 'Save changes', tag: 'button' } } });
await click(page, 'Save changes', { tag: 'button' });
await sleep(1500);
await dump('saved product', 300);

const place = { 'Instant Coffee': 'Beverages', 'Bottled Juice': 'Beverages', 'Corn Chips': 'Snack/Merienda', Peanuts: 'Snack/Merienda', 'Instant Noodles': 'Varieties' };
const groups = (await api(page, 'GET', '/api/v1/menu-groups?per_page=100')).body.data;
for (const [name, group] of Object.entries(place)) {
    const found = (await api(page, 'GET', `/api/v1/products?search=${encodeURIComponent(name)}&per_page=5`)).body.data[0];
    const target = groups.find((g) => g.name === group);
    const keep = ['sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'unit_of_measure', 'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level'];
    const body = { ...Object.fromEntries(keep.map((k) => [k, found[k] ?? null])), menu_group_id: target.id };
    const r = await api(page, 'PATCH', `/api/v1/products/${found.id}`, body);
    if (r.status !== 200) console.log(JSON.stringify(r.body).slice(0, 300));
    console.log('place', name, '->', group, r.status);
}
await go(page, '/admin/catalog/products');
await sleep(800);

saveAnnotations();
await browser.close();

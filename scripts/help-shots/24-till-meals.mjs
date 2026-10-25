/**
 * Canteen help pictures, part 3: the till -- the group tabs and meal tiles, the meal picker (dish category with its price, dish,
 * rice pop-up), a Combo Meal (main and side), the cart, and the sale as it is recorded (guide "Sell a Standard or Combo Meal").
 * Needs the groups (script 22) and the meal menu (script 23). Nothing is refunded or voided here; the refund panel is only opened.
 */
import { launch, ensureLogin, go, click, fill, shot, saveAnnotations, sleep, tall, normal, text, setValue, pick } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
const dump = async (l, n = 700) => console.log(l, '::', (await page.evaluate(() => document.body.innerText)).replace(/\n+/g, ' | ').slice(0, n));
await ensureLogin(page, 'liza@tindaflow.test', 'Cashier-2026!');
await go(page, '/pos');
await sleep(1000);
if (await page.$('#opening_cash')) {
    await fill(page, '#opening_cash', '500.00');
    await click(page, 'Open shift', { tag: 'button' });
    await sleep(1800);
}
await dump('register');
/** Clicks a tab by its name (a tab also shows a product count while selected, so the text is matched from the start). */
const tab = async (list, name) => {
    const handle = await page.evaluateHandle((list, name) => [...document.querySelectorAll(`[role=tablist][aria-label="${list}"] button`)].find((b) => b.innerText.trim().toLowerCase().startsWith(name.toLowerCase())) ?? null, list, name);
    const el = handle.asElement();
    if (!el) throw new Error(`no tab ${name} in ${list}`);
    await el.click();
    await sleep(800);
};

// ---------------------------------------------------------------- the group tabs, sub-tabs and meal tiles
await tab('Menu groups', 'Lunch');
await shot(page, 'canteen-register', {
    targets: {
        tabs: { sel: '[role=tablist][aria-label="Menu groups"]' },
        lunch: { sel: '[role=tablist][aria-label="Menu groups"] button', nth: 2 },
        subtabs: { sel: '[role=tablist][aria-label="Lunch sub-groups"]' },
        meals: { sel: 'ul[aria-label="Meals"]' },
        standard: { sel: 'ul[aria-label="Meals"] li button', nth: 0 },
    },
});
await tab('Lunch sub-groups', 'Standard Meal');

// ---------------------------------------------------------------- Standard Meal: category, dish, rice
await tall(page, 1000); // the picker scrolls inside the products area; a taller window keeps the Add button in the picture
const picker = '[role=dialog][aria-label="Choose Standard Meal"]';
await click(page, 'Meal · choose options', { tag: 'button', exact: false });
await sleep(700);
await dump('picker', 500);
await shot(page, 'meal-category', { targets: { categories: { sel: `${picker} ul.mb-4` }, beef: { sel: `${picker} ul.mb-4 li button`, nth: 0 } } });
await (await page.$$(`${picker} ul.mb-4 li button`))[0].click();
await sleep(600);
await shot(page, 'meal-dish', { targets: { beef: { sel: `${picker} ul.mb-4 li button`, nth: 0 }, dishes: { sel: `${picker} ul.grid` }, nilaga: { text: 'Beef nilaga', tag: 'button', within: picker } } });
await click(page, 'Beef nilaga', { tag: 'button', within: picker });
await sleep(700);
await dump('after dish', 500);
const rice = '[role=dialog][aria-label="Extra rice"]';
await shot(page, 'meal-rice', { targets: { popup: { sel: rice }, whole: { text: 'Whole rice', tag: 'button', within: rice, exact: false }, half: { text: 'Half rice', tag: 'button', within: rice, exact: false }, skip: { text: 'Skip', tag: 'button', within: rice } } });
await click(page, 'Whole rice', { tag: 'button', within: rice, exact: false });
await sleep(600);
await shot(page, 'meal-ready', { targets: { rice: { text: 'Extra rice:', exact: false, tag: 'span' }, add: { text: 'Add to order', exact: false, tag: 'button' } } });
await click(page, 'Add to order', { tag: 'button', exact: false });
await sleep(700);

// ---------------------------------------------------------------- Combo Meal: main, side, rice
await tab('Lunch sub-groups', 'Combo Meal');
const combo = '[role=dialog][aria-label="Choose Combo Meal"]';
await click(page, 'Meal · choose options', { tag: 'button', exact: false });
await sleep(700);
await (await page.$$(`${combo} ul.mb-4 li button`))[0].click();
await sleep(500);
await click(page, 'Beef caldereta', { tag: 'button', within: combo });
await sleep(700);
await dump('combo after main', 700);
await shot(page, 'combo-side', { targets: { main: { text: 'Beef caldereta', tag: 'button', within: combo }, side: { text: 'Side', exact: false, tag: 'p', within: combo }, pinakbet: { text: 'Pinakbet', tag: 'button', within: combo } } });
await click(page, 'Pinakbet', { tag: 'button', within: combo });
await sleep(700);
await click(page, 'Half rice', { tag: 'button', within: '[role=dialog][aria-label="Extra rice"]', exact: false });
await sleep(600);
await shot(page, 'combo-ready', { targets: { main: { text: 'Beef caldereta', tag: 'button', within: combo }, pinakbet: { text: 'Pinakbet', tag: 'button', within: combo }, add: { text: 'Add to order', exact: false, tag: 'button' } } });
await click(page, 'Add to order', { tag: 'button', exact: false });
await sleep(700);

// ---------------------------------------------------------------- the cart with both meals and a plain product
await tab('Menu groups', 'All');
await click(page, 'Corn Chips (60g)', { tag: 'button', exact: false });
await sleep(600);
await dump('cart', 900);
await shot(page, 'meal-cart', { targets: { cart: { sel: 'section[aria-label="Cart"]' }, standard: { sel: 'ul[aria-label$=" selection"]', nth: 0 }, combo: { sel: 'ul[aria-label$=" selection"]', nth: 1 }, total: { sel: '[aria-label="Total to charge"]' }, charge: { text: 'Charge', exact: false, tag: 'button' } } });

// ---------------------------------------------------------------- take the cash, then see how the sale was recorded
await click(page, 'Charge', { exact: false, tag: 'button' });
await sleep(900);
await tall(page, 1100);
await click(page, 'Exact', { tag: 'button' });
await click(page, 'Complete sale', { exact: false, tag: 'button' });
await sleep(2200);
await dump('receipt', 500);
console.log(await page.evaluate(() => { const el = [...document.querySelectorAll('*')].find((e) => e.children.length === 0 && /Whole rice/.test(e.textContent)); const out = []; for (let n = el; n && out.length < 5; n = n.parentElement) out.push(n.tagName + '.' + n.className.toString().slice(0, 60) + ' ' + JSON.stringify(n.getAttribute('aria-label'))); return out.join(' <- '); }));
await normal(page);
await shot(page, 'meal-receipt', { clip: { x: 300, y: 40, w: 600, h: 700 }, targets: { lines: { closest: 'ul, ol, div.divide-y', of: { text: 'Whole rice', tag: 'span, p, li, div', exact: false } }, total: { text: 'Grand total', tag: 'dt' } } });
await click(page, 'New sale', { tag: 'button' });
await sleep(800);
await go(page, '/pos/lookup');
await sleep(1500);
await click(page, '000005', { tag: 'button', exact: false });
await sleep(1500);
await dump('detail', 1200);
await tall(page, 1100);
await shot(page, 'meal-lookup-detail', {
    targets: { meal: { text: 'Standard Meal - Beef nilaga', tag: 'p, span, li, div', exact: false, nth: 1 }, rice: { text: 'Whole rice', tag: 'p, span, li, div', exact: false, nth: 1 }, refund: { text: 'Request refund', tag: 'button' } },
}).catch((e) => console.log('shot err', e.message));
await click(page, 'Request refund', { tag: 'button' });
await sleep(900);
const inputs = await page.$$('[role=dialog] input[type=text], [role=dialog] input:not([type])');
const selects = await page.$$('[role=dialog] select');
// items: Corn Chips, Standard Meal, Whole rice, Combo Meal, Half rice -> only the Whole rice line (third) comes back
await setValue(page, inputs[2], '1');
await pick(page, selects[2], 'Disposed');
await setValue(page, inputs[5], '15.00');
await fill(page, '[role=dialog] textarea', 'Customer did not want the rice; it was not served.');
await sleep(400);
await shot(page, 'meal-refund-rice', {
    clip: { x: 720, y: 0, w: 480, h: 1100 },
    targets: { rice: { text: 'Whole rice', tag: 'p, span, div', exact: false, within: '[role=dialog]' }, qty: { sel: '[role=dialog] input', nth: 2 }, amount: { sel: '[role=dialog] input', nth: 5 }, reason: { sel: '[role=dialog] textarea' }, request: { text: 'Request refund', tag: 'button', within: '[role=dialog]' } },
}).catch((e) => console.log('shot err', e.message));
await click(page, 'Cancel', { tag: 'button', within: '[role=dialog]' }); // nothing is requested: the panel was only opened to show the rice line on its own
await sleep(500);
await normal(page);
saveAnnotations();
await browser.close();

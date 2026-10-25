/**
 * Canteen help pictures, part 2: the Meal menu admin screens -- dishes, a meal's steps, the price grid and price history
 * (guide "Build a meal and its prices"). The canteen's dishes and its Standard and Combo Meal come from MealMenuSeeder, the
 * same data a real canteen would start from; nothing here is saved except one scheduled price change (to show the history).
 */
import { seed } from './canteen.mjs';
import { launch, ensureLogin, go, click, fill, input, shot, saveAnnotations, sleep, find, setValue, pick, tall, normal, text } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
// The screens show times in the store's zone (Asia/Manila) and read the "Starts" box in the browser's zone, so the capture
// browser runs in the store's zone, as a Philippine till would.
await page.emulateTimezone('Asia/Manila');
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
const dump = async (l, n = 700) => console.log(l, '::', (await page.evaluate(() => (document.querySelector('[role=dialog]') ?? document.querySelector('main')).innerText)).replace(/\n+/g, ' | ').slice(0, n));
const into = async (selector) => (await page.$(selector)).evaluate((e) => e.scrollIntoView({ block: 'center' }));

seed('MealMenuSeeder');

// ---------------------------------------------------------------- dishes
await go(page, '/admin/menu/dishes');
await dump('dishes');
await shot(page, 'menu-dishes', { targets: { nav: { sel: 'nav[aria-label="Back office"]' }, category: { sel: 'section[aria-label="Beef"]' }, 'add-category': { text: 'Add dish category', tag: 'button' }, 'add-dish': { text: 'Add dish', tag: 'button', nth: 0 }, hide: { text: 'Hide', tag: 'button', nth: 1 } } });
await click(page, 'Add dish', { tag: 'button', nth: 0 });
await fill(page, 'New Beef dish', 'Beef tapa');
await shot(page, 'menu-dish-add', { targets: { name: { input: 'New Beef dish' }, add: { sel: 'section[aria-label="Beef"] form button[type=submit]' }, move: { sel: 'select[id^=move_]', nth: 0 } } });
await click(page, 'Cancel', { tag: 'button' });

// ---------------------------------------------------------------- a meal and its steps
await go(page, '/admin/menu/meals');
await click(page, 'Combo Meal', { tag: 'button', within: 'aside[aria-label="Meals"]' });
await sleep(700);
await dump('combo');
await shot(page, 'menu-meals', { targets: { list: { sel: 'aside[aria-label="Meals"]' }, place: { sel: '#meal_menu_group' }, prices: { text: 'Prices', tag: 'a' }, pricing: { text: 'PRICES THE MEAL', exact: false, tag: 'span' }, steps: { sel: 'ol[aria-label="Steps"]' } } });
await into('ul[aria-label="Extra rice options"]');
await shot(page, 'menu-meal-addon', { targets: { step: { closest: 'li', of: { sel: 'ul[aria-label="Extra rice options"]' } }, whole: { text: 'Whole rice', tag: 'span', exact: false }, add: { text: 'Add an add-on', tag: 'button' } } });
await click(page, 'Add a step', { tag: 'button' });
await sleep(500);
await fill(page, 'Step name', 'Soup');
await into('#new_step_name, form input[placeholder^="e.g. Side"]');
await shot(page, 'menu-step-form', { targets: { name: { input: 'Step name' }, kind: { input: 'What does this step offer?' }, fewest: { input: 'Fewest picks' }, most: { input: 'Most picks' }, popup: { text: 'Open this step automatically', exact: false, tag: 'label' }, add: { text: 'Add step', tag: 'button' } } });
await click(page, 'Cancel', { tag: 'button', nth: 0 }).catch(() => {});

// ---------------------------------------------------------------- the price grid, a scheduled change and the history
await go(page, '/admin/menu/prices');
const meal = await page.$('#price_meal');
await pick(page, meal, 'Standard');
await sleep(1200);
await dump('prices');
await tall(page, 1000);
await shot(page, 'menu-prices', { targets: { meal: { sel: '#price_meal' }, grid: { sel: 'ul[aria-label="Standard Meal prices"]' }, change: { text: 'Change price', tag: 'button', nth: 0 }, history: { text: 'History', tag: 'button', nth: 0 }, addons: { text: 'add-on', exact: false, tag: 'span', nth: 0 } } });
await click(page, 'Change price', { tag: 'button', nth: 0 });
await sleep(500);
const day = new Date(Date.now() + 36 * 3600 * 1000).toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const local = `${day}T06:00`;
await fill(page, 'New price (₱)', '72');
await setValue(page, await input(page, 'Starts (leave empty for now)'), local);
await fill(page, 'Reason (optional)', 'Beef cost went up');
await sleep(400);
await shot(page, 'menu-price-form', { targets: { amount: { input: 'New price (₱)' }, starts: { input: 'Starts (leave empty for now)' }, reason: { input: 'Reason (optional)' }, save: { text: 'Save price', tag: 'button' } } });
await click(page, 'Save price', { tag: 'button' });
await sleep(1500);
await dump('after price');
await shot(page, 'menu-price-saved', { targets: { next: { text: 'Next:', exact: false, tag: 'p' }, current: { sel: 'ul[aria-label="Standard Meal prices"] li', nth: 0 }, history: { sel: 'section[aria-label="Price history"] table' } } });
await normal(page);

saveAnnotations();
await browser.close();

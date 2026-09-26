import { launch, ensureLogin, login, go, click, fill, input, shot, saveAnnotations, sleep, find, setValue, today, text, reload } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });
await ensureLogin(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');

// ---------------------------------------------------------------- the till before this browser is a terminal
await go(page, '/pos');
console.log('pos (not enrolled):', (await text(page)).replace(/\n+/g, ' | ').slice(0, 200));
await shot(page, 'till-not-enrolled', { clip: { x: 300, y: 40, w: 600, h: 260 }, targets: { message: { sel: 'p.text-amber-300' } } });

// ---------------------------------------------------------------- terminals: issue a token, enrol this browser
await go(page, '/admin/terminals');
await shot(page, 'terminals-before', {
    targets: { 'this-browser': { sel: 'section' }, token: { text: 'Enrollment token', tag: 'button' } },
});
await click(page, 'Enrollment token', { tag: 'button' });
await sleep(800);
await shot(page, 'terminals-token', {
    targets: {
        'token-box': { sel: 'code' },
        'enroll-with': { text: 'Enroll this browser with it' },
        'expires': { text: 'Shown once', exact: false, tag: 'p' },
    },
});
await click(page, 'Enroll this browser with it');
await sleep(900);
await shot(page, 'terminals-enrolled', { targets: { 'this-browser': { sel: 'section' } } });

// ---------------------------------------------------------------- open a shift before setup is done: the till says what is missing
await go(page, '/pos');
await shot(page, 'till-open-shift-admin', { targets: {} });
await fill(page, 'opening_cash', '0.00').catch(async () => { const el = await page.$('#opening_cash'); await el.type('0.00'); });
await click(page, 'Open shift');
await sleep(1200);
console.log('after open shift:', (await text(page)).replace(/\n+/g, ' | ').slice(0, 400));
await shot(page, 'till-setup-incomplete', { clip: { x: 300, y: 10, w: 600, h: 400 }, targets: { message: { sel: 'p.text-amber-300' }, list: { sel: 'ul.space-y-1' }, 'check-again': { text: 'Check again' }, 'back': { text: 'Back to dashboard' } } });

saveAnnotations();
await browser.close();

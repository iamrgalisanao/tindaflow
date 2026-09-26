import { launch, login, logout, go, click, shot, saveAnnotations, sleep, find } from './lib.mjs';

const { browser, page } = await launch({ scale: 2 });

// ---- Sign in page (signed out) -----------------------------------------------------------------------------------
await go(page, '/login');
await shot(page, 'signin-login', {
    clip: { x: 300, y: 190, w: 600, h: 390 },
    targets: { email: { input: 'Email' }, password: { input: 'Password' }, submit: { sel: 'button[type=submit]' } },
});

// ---- Admin dashboard ---------------------------------------------------------------------------------------------
await login(page, 'admin@tindaflow.test', 'Guide-Admin-2026!');
await go(page, '/');
await shot(page, 'signin-dashboard', {
    targets: {
        menu: { sel: 'nav[aria-label="Back office"]' },
        today: { sel: 'section[aria-label="Today\'s sales"]' },
        'open-till': 'Open the till',
        'sign-out': { sel: 'button[aria-label="Sign out"]' },
    },
});

// ---- Cashier dashboard ---------------------------------------------------------------------------------------------
await logout(page);
await login(page, 'cashier@demo.local', 'password');
await go(page, '/');
await shot(page, 'signin-cashier', {
    targets: { menu: { sel: 'nav[aria-label="Back office"]' }, 'open-till': 'Open the till' },
});

saveAnnotations();
await browser.close();

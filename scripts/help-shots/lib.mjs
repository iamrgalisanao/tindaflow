/**
 * Shared helpers for the Help screenshot scripts (scripts/help-shots/run.sh). Every screenshot is taken in a real
 * browser against a scratch copy of the app, and each named target is stored as a box (percent of the picture) in
 * resources/js/pages/help/shots/annotations.json, which is what the animated "click here" layer draws on.
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

export const BASE = process.env.HELP_SHOTS_BASE ?? 'http://localhost:8001';
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launch({ scale = 1, width = 1200, height = 760 } = {}) {
    const browser = await puppeteer.launch({
        executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: true,
        protocolTimeout: 90000,
        userDataDir: process.env.HELP_SHOTS_PROFILE ?? path.join(os.tmpdir(), 'tindaflow-help-shots-profile'),
        args: ['--no-sandbox', '--font-render-hinting=none'],
    });
    // A Chrome that will not shut down must never leave the whole capture waiting: give it a moment, then kill it.
    const close = browser.close.bind(browser);
    browser.close = async () => {
        await Promise.race([close().catch(() => {}), sleep(8000)]);
        browser.process()?.kill('SIGKILL');
    };
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: scale });
    page.on('pageerror', (e) => console.log('[pageerror]', e.message));
    return { browser, page };
}

export async function go(page, url, settle = 900) {
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            await page.goto(BASE + url, { waitUntil: 'networkidle0' });
            break;
        } catch (e) {
            if (attempt === 2 || !/ERR_ABORTED|Navigation|detached/.test(String(e))) throw e;
            await sleep(800);
        }
    }
    await sleep(settle);
}

export async function login(page, email, password) {
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            await go(page, '/login');
            await page.waitForSelector('input[type=email]', { timeout: 10000 });
            await page.type('input[type=email]', email);
            await page.type('input[type=password]', password);
            await page.click('button[type=submit]');
            await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 });
            await sleep(1200);
            return;
        } catch (e) {
            if (attempt === 2) throw e;
            await sleep(1000);
        }
    }
}

export async function logout(page) {
    await page.evaluate(async () => {
        const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]*)/) || [])[1] || '');
        await fetch('/api/v1/auth/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': t, Accept: 'application/json' } });
    });
}

/** Find a visible interactive element by its text, aria-label or placeholder. Returns an ElementHandle or null. */
export async function find(page, text, { tag = null, exact = true, nth = 0, within = null } = {}) {
    const selector = tag ?? 'button, a, [role=button], summary, label, th, td, li, h2, h3, p, span, div, input, textarea, select';
    const handle = await page.evaluateHandle(
        (text, tag, exact, nth, within) => {
            const root = within ? document.querySelector(within) : document;
            if (!root) return null;
            const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
            const visible = (el) => {
                const r = el.getBoundingClientRect();
                const cs = getComputedStyle(el);
                return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
            };
            const hits = [];
            for (const el of root.querySelectorAll(tag)) {
                const cands = [norm(el.innerText), el.getAttribute('aria-label'), el.getAttribute('placeholder'), el.getAttribute('title'), el.value && el.tagName !== 'BUTTON' ? '' : ''];
                const own = norm(el.innerText);
                const lc = (v) => (v || '').toLowerCase(); const match = cands.some((c) => c && (exact ? lc(c) === lc(text) : lc(c).includes(lc(text))));
                if (match && visible(el)) { let d = 0; for (let n = el; n; n = n.parentElement) d++; const interactive = /^(BUTTON|A|INPUT|SELECT|TEXTAREA|LABEL|SUMMARY)$/.test(el.tagName) ? 0 : 1; hits.push({ el, len: own.length, d, interactive }); }
            }
            // prefer the smallest (deepest) element for a text match
            hits.sort((a, b) => a.interactive - b.interactive || a.len - b.len || b.d - a.d);
            const picked = hits.filter((h, i, arr) => arr.findIndex((o) => o.el === h.el) === i);
            return picked[nth]?.el ?? null;
        },
        text, selector, exact, nth, within,
    );
    const el = handle.asElement();
    return el;
}

export async function click(page, text, opts) {
    const el = await find(page, text, opts);
    if (!el) throw new Error(`click: not found "${text}"`);
    await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await el.click();
    await sleep(700);
}

export async function fill(page, selectorOrLabel, value) {
    // by label text, placeholder, or CSS selector
    let el = null;
    if (/^[#.]|\[|^(input|textarea|select)\b/.test(selectorOrLabel)) { try { el = await page.$(selectorOrLabel); } catch { el = null; } }
    if (!el) {
        const h = await page.evaluateHandle((t) => {
            const norm = (s) => (s || '').replace(/\s+/g, ' ').trim().replace(/\s*\*$/, '');
            for (const l of document.querySelectorAll('label')) {
                if (norm(l.innerText) === t || norm(l.innerText).startsWith(t)) {
                    const id = l.getAttribute('for');
                    const c = id ? document.getElementById(id) : l.querySelector('input,textarea,select');
                    if (c) return c;
                }
            }
            return document.querySelector(`[placeholder="${t}"], [aria-label="${t}"]`);
        }, selectorOrLabel);
        el = h.asElement();
    }
    if (!el) throw new Error(`fill: not found "${selectorOrLabel}"`);
    await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await setValue(page, el, '');
    await el.focus();
    if (value !== '') await el.type(String(value), { delay: 6 });
    return el;
}

export async function text(page) {
    return page.evaluate(() => document.body.innerText);
}

export async function api(page, method, url, body, headers = {}) {
    return page.evaluate(
        async (method, url, body, headers) => {
            const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]*)/) || [])[1] || '');
            const r = await fetch(url, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': t, ...headers },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            const txt = await r.text();
            return { status: r.status, body: txt ? JSON.parse(txt) : null };
        },
        method, url, body, headers,
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Screenshots with named target boxes (percent of the picture) -- written to annotations.json next to the images.
// ---------------------------------------------------------------------------------------------------------------

export const OUT = process.env.HELP_SHOTS_OUT ?? path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/pages/help/shots');
export const annotations = {};

export async function input(page, label) {
    const h = await page.evaluateHandle((t) => {
        const norm = (s) => (s || '').replace(/\s+/g, ' ').trim().replace(/\s*\*$/, '');
        for (const l of document.querySelectorAll('label')) {
            if (norm(l.innerText) === t) {
                const id = l.getAttribute('for');
                const c = id ? document.getElementById(id) : l.querySelector('input,textarea,select');
                if (c) return c;
            }
        }
        return document.querySelector(`[placeholder="${t}"], [aria-label="${t}"]`);
    }, label);
    return h.asElement();
}

async function resolveEl(page, spec) {
    if (typeof spec === 'string') return find(page, spec);
    if (spec.input) return input(page, spec.input);
    if (spec.sel) return (await page.$$(spec.sel))[spec.nth ?? 0] ?? null;
    if (spec.text) return find(page, spec.text, spec);
    return null;
}

/** Resolve a target spec to a bounding box in viewport CSS pixels. */
export async function boxOf(page, spec) {
    if (spec.union) {
        const boxes = [];
        for (const s of spec.union) boxes.push(await boxOf(page, s));
        const x1 = Math.min(...boxes.map((b) => b.x));
        const y1 = Math.min(...boxes.map((b) => b.y));
        const x2 = Math.max(...boxes.map((b) => b.x + b.w));
        const y2 = Math.max(...boxes.map((b) => b.y + b.h));
        return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }
    if (spec.rect) return { x: spec.rect[0], y: spec.rect[1], w: spec.rect[2], h: spec.rect[3] };
    let el = null;
    for (let attempt = 0; attempt < 6 && !el; attempt++) {
        if (attempt > 0) await sleep(450);
        if (spec.closest) {
            const inner = await resolveEl(page, spec.of);
            el = inner ? (await inner.evaluateHandle((e, s) => e.closest(s), spec.closest)).asElement() : null;
        } else if (typeof spec === 'string') el = await find(page, spec);
        else if (spec.input) el = await input(page, spec.input);
        else if (spec.sel) el = (await page.$$(spec.sel))[spec.nth ?? 0] ?? null;
        else if (spec.text) el = await find(page, spec.text, spec);

    }
    if (!el) throw new Error(`target not found: ${JSON.stringify(spec)}`);
    const b = await el.evaluate((e) => {
        const r = e.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    });
    return b;
}

/**
 * shot(page, id, { targets: { name: spec }, clip: {x,y,w,h}, pad: 4 })
 * Saves <id>.webp (2x) and records each target as [left, top, width, height] percent of the picture.
 */
export async function shot(page, id, { targets = {}, clip = null, pad = 5, padBy = {} } = {}) {
    const vp = page.viewport();
    const area = clip ?? { x: 0, y: 0, w: vp.width, h: vp.height };
    const t = {};
    for (const [name, spec] of Object.entries(targets)) {
        const b = await boxOf(page, spec);
        const p = padBy[name] ?? pad;
        let x = b.x - p - area.x, y = b.y - p - area.y, w = b.w + p * 2, h = b.h + p * 2;
        if (x < 0) { w += x; x = 0; }
        if (y < 0) { h += y; y = 0; }
        if (x + w > area.w) w = area.w - x;
        if (y + h > area.h) h = area.h - y;
        if (w <= 2 || h <= 2) throw new Error(`target "${name}" of ${id} is outside the picture`);
        t[name] = [x / area.w, y / area.h, w / area.w, h / area.h].map((v) => Math.round(v * 10000) / 100);
    }
    fs.mkdirSync(OUT, { recursive: true });
    const scroll = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
    await page.screenshot({
        path: path.join(OUT, `${id}.webp`),
        type: 'webp',
        quality: 82,
        clip: { x: area.x + scroll.x, y: area.y + scroll.y, width: area.w, height: area.h },
    });
    annotations[id] = { w: Math.round(area.w), h: Math.round(area.h), t };
    console.log(`  shot ${id}  (${Object.keys(t).length} targets)`);
}

export function saveAnnotations() {
    const file = path.join(OUT, 'annotations.json');
    let prior = {};
    try { prior = JSON.parse(fs.readFileSync(file, 'utf8')); } catch {}
    const merged = { ...prior, ...annotations };
    const sorted = Object.fromEntries(Object.entries(merged).sort(([a], [b]) => a.localeCompare(b)));
    fs.writeFileSync(file, JSON.stringify(sorted, null, 1) + '\n');
}

export async function setValue(page, el, value) {
    await el.evaluate((e, v) => {
        const proto = e.tagName === 'SELECT' ? HTMLSelectElement.prototype : e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
        Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v);
        e.dispatchEvent(new Event('input', { bubbles: true }));
        e.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
}

export const today = () => new Date().toLocaleDateString('en-CA');

export async function reload(page, settle = 900) {
    await page.reload({ waitUntil: 'networkidle0' });
    await sleep(settle);
}

export async function scrollMain(page, y) {
    await page.evaluate((y) => { window.scrollTo(0, y); }, y);
    await sleep(300);
}


export async function ensureLogin(page, email, password) {
    await go(page, '/');
    if (page.url().includes('/login')) {
        await login(page, email, password);
        return;
    }
    const me = await api(page, 'GET', '/api/v1/auth/me');
    if (me.body?.email !== email) {
        await logout(page);
        await login(page, email, password);
    }
}

export async function enrolled(page) {
    const r = await api(page, 'GET', '/api/v1/terminal/current');
    return r.status === 200;
}

/** Choose a <select> option by its visible text (or the first non-empty one when text is omitted). */
export async function pick(page, selectEl, optionText) {
    const value = await selectEl.evaluate((s, t) => {
        const o = [...s.options].find((o) => (t ? o.text.trim().toLowerCase().includes(t.toLowerCase()) : o.value));
        return o?.value ?? null;
    }, optionText ?? null);
    if (value === null) throw new Error(`no option "${optionText}"`);
    await setValue(page, selectEl, value);
}

export const PANEL = { x: 720, y: 0, w: 480, h: 760 };

export async function scrollPanel(page, sel, to = 'bottom') {
    await page.$eval(sel, (e, to) => { e.scrollTop = to === 'bottom' ? e.scrollHeight : 0; }, to);
    await sleep(300);
}

export async function tall(page, h = 900) { const v = page.viewport(); await page.setViewport({ ...v, height: h }); await sleep(300); }
export async function normal(page) { const v = page.viewport(); await page.setViewport({ ...v, width: 1200, height: 760 }); await sleep(300); }
export async function wide(page, w = 1440) { const v = page.viewport(); await page.setViewport({ ...v, width: w }); await sleep(400); }

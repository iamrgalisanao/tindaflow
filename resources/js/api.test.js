import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { apiFetch, SESSION_EXPIRED_EVENT } from './api';
import { request } from './pages/admin/catalog/catalogApi';

const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
// A Response body can be read once, so a mock that answers several calls must build a fresh one each time.
const always = (status, body) => vi.fn().mockImplementation(() => Promise.resolve(json(status, body)));

let expired;
const onExpired = () => {
    expired += 1;
};

beforeEach(() => {
    expired = 0;
    window.addEventListener(SESSION_EXPIRED_EVENT, onExpired);
    document.cookie = 'XSRF-TOKEN=abc%3D; path=/';
});
afterEach(() => {
    window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired);
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('apiFetch: expired session', () => {
    it('signals session-expired on a 401 from an ordinary endpoint', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(401, { error: { code: 'AUTHENTICATION_REQUIRED' } })));

        const result = await apiFetch('/api/v1/sales', { method: 'POST', body: {} });

        expect(result.status).toBe(401);
        expect(expired).toBe(1);
    });

    it('does NOT signal for /auth/* (the boot-time /auth/me of a signed-out visitor, or a wrong password)', async () => {
        vi.stubGlobal('fetch', always(401, { error: {} }));

        await apiFetch('/api/v1/auth/me');
        await apiFetch('/api/v1/auth/login', { method: 'POST', body: {} });

        expect(expired).toBe(0);
    });

    it('does not signal for other statuses', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(403, { error: {} })));

        await apiFetch('/api/v1/products');

        expect(expired).toBe(0);
    });
});

describe('apiFetch: requests', () => {
    it('sends the CSRF token from the cookie on a write, and JSON with the Idempotency-Key it was given', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json(201, { id: 's1' }));
        vi.stubGlobal('fetch', fetchMock);

        await apiFetch('/api/v1/sales', { method: 'POST', headers: { 'Idempotency-Key': 'k1' }, body: { a: 1 } });

        const [, init] = fetchMock.mock.calls[0];
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc=');
        expect(init.headers['Idempotency-Key']).toBe('k1');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.body).toBe('{"a":1}');
    });

    it('does not send a CSRF token on a GET', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json(200, {}));
        vi.stubGlobal('fetch', fetchMock);

        await apiFetch('/api/v1/products');

        expect(fetchMock.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBeUndefined();
    });
});

describe('apiFetch: a deadlock (CONCURRENCY_CONFLICT) is retried ONCE, with the same key', () => {
    it('retries a write that carries an Idempotency-Key', async () => {
        vi.useFakeTimers();
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(json(409, { error: { code: 'CONCURRENCY_CONFLICT' } }))
            .mockResolvedValueOnce(json(201, { id: 's1' }));
        vi.stubGlobal('fetch', fetchMock);

        const pending = apiFetch('/api/v1/sales', { method: 'POST', headers: { 'Idempotency-Key': 'k1' }, body: {} });
        await vi.advanceTimersByTimeAsync(1000);
        const result = await pending;

        expect(result.status).toBe(201);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1].headers['Idempotency-Key']).toBe('k1');
    });

    it('never retries a write that has no key', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json(409, { error: { code: 'CONCURRENCY_CONFLICT' } }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await apiFetch('/api/v1/shifts/1/x-readings', { method: 'POST' });

        expect(result.status).toBe(409);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('request(): what a dropped connection looks like to a screen', () => {
    it('is status 0 when nothing arrives', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        expect(await request('/api/v1/sales', { method: 'POST', body: {} })).toEqual({ ok: false, status: 0, body: null });
    });

    it('is status 502 when a gateway answers with something that is not JSON', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 })));

        expect(await request('/api/v1/sales', { method: 'POST', body: {} })).toEqual({ ok: false, status: 502, body: null });
    });
});

/**
 * Thin fetch wrapper matching api-design.md's already-frozen same-origin
 * session-cookie + double-submit CSRF model exactly -- no new client-side
 * auth mechanism, no HTTP client library. The XSRF-TOKEN cookie is set
 * automatically by Laravel's `web` middleware group on every response
 * (already proven by AuthenticationSessionTest's CSRF-bootstrap test);
 * this file only ever reads it back and echoes it as the required header.
 */

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * @param {string} path e.g. "/api/v1/auth/login"
 * @param {RequestInit} [options]
 * @returns {Promise<{ok: boolean, status: number, body: any}>}
 */
export async function apiFetch(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = {
        Accept: 'application/json',
        ...options.headers,
    };

    // A caller that names its own Content-Type (a CSV upload) sends the body as it is; everything else is JSON.
    const rawBody = options.headers?.['Content-Type'] !== undefined;
    if (options.body !== undefined && !rawBody) {
        headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET' && method !== 'HEAD') {
        const token = xsrfToken();
        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    const response = await fetch(path, {
        ...options,
        method,
        headers,
        credentials: 'same-origin',
        body: options.body === undefined || rawBody ? options.body : JSON.stringify(options.body),
    });

    const text = await response.text();
    const body = text ? JSON.parse(text) : null;

    // The server answers 409 CONCURRENCY_CONFLICT when the database rolled a request back because it lost a race with
    // another one (a deadlock), or when the same Idempotency-Key is still being processed. Nothing was saved, and a write
    // that carries an Idempotency-Key is safe to send again -- it is applied once and a repeat returns the first result --
    // so it is retried ONCE after a short, random pause. A request without a key is never retried on its own.
    if (
        response.status === 409 &&
        body?.error?.code === 'CONCURRENCY_CONFLICT' &&
        options.headers?.['Idempotency-Key'] !== undefined &&
        !options.conflictRetried
    ) {
        await new Promise((resolve) => setTimeout(resolve, 250 + Math.random() * 350));

        return apiFetch(path, { ...options, conflictRetried: true });
    }

    return { ok: response.ok, status: response.status, body };
}

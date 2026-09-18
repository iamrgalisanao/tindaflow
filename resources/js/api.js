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

    if (options.body !== undefined) {
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
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });

    const text = await response.text();
    const body = text ? JSON.parse(text) : null;

    return { ok: response.ok, status: response.status, body };
}

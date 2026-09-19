import { apiFetch } from '../../../api';

/**
 * apiFetch throws when the request never completes (TypeError) or the body is not JSON (SyntaxError).
 * Catalog screens want a result object either way.
 *
 * @returns {Promise<{ok: boolean, status: number, body: any}>}
 */
export async function request(path, options) {
    try {
        return await apiFetch(path, options);
    } catch (error) {
        return { ok: false, status: error instanceof SyntaxError ? 502 : 0, body: null };
    }
}

/** Every page of a paginated list (per_page is capped at 100 by the contract). */
export async function fetchAll(path) {
    const rows = [];
    for (let page = 1; page <= 20; page += 1) {
        const result = await request(`${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`);
        if (!result.ok) {
            return { ok: false, status: result.status, rows };
        }
        rows.push(...result.body.data);
        if (page >= result.body.meta.last_page) {
            break;
        }
    }
    return { ok: true, status: 200, rows };
}

/** 422 VALIDATION_FAILED -> { field: 'first message' }. */
export function fieldErrors(result) {
    if (result.status !== 422 || result.body?.error?.code !== 'VALIDATION_FAILED') {
        return {};
    }
    return Object.fromEntries(Object.entries(result.body.error.details ?? {}).map(([field, messages]) => [field, messages[0]]));
}

export function failureMessage(result, whatFailed) {
    if (result.status === 0) {
        return `${whatFailed} Check your connection and try again.`;
    }
    if (result.status === 401) {
        return 'Your session has expired. Sign in again to continue.';
    }
    if (result.status === 403) {
        return 'Your role does not allow this action.';
    }
    if (result.status >= 500) {
        return `${whatFailed} The server hit a problem; try again in a moment.`;
    }
    return result.body?.error?.message ?? whatFailed;
}

/** Money is a string with exactly two decimals (openapi.yaml Money); "55" and "55.5" are completed, anything else is rejected. */
export function normalizeMoney(text) {
    const cleaned = String(text).trim().replace(/^₱/, '').replaceAll(',', '');
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(cleaned)) {
        return null;
    }
    const [whole, fraction = ''] = cleaned.split('.');
    return `${whole}.${`${fraction}00`.slice(0, 2)}`;
}

export const TAX_CLASSES = [
    { id: 'VATABLE', label: 'VATABLE', hint: 'Sold with VAT.' },
    { id: 'VAT_EXEMPT', label: 'VAT EXEMPT', hint: 'Sold without VAT (exempt).' },
    { id: 'ZERO_RATED', label: 'ZERO RATED', hint: 'Sold at a 0% VAT rate.' },
    { id: 'NON_VAT', label: 'NON-VAT', hint: 'Not subject to VAT.' },
];

/**
 * productExport as a file download. Resolves { ok, status, filename }; a failure carries the status so the
 * caller can say why (401 -> sign in again, 403 -> not allowed).
 */
export async function downloadProductsCsv() {
    try {
        const response = await fetch('/api/v1/products/export', { headers: { Accept: 'text/csv' }, credentials: 'same-origin' });
        if (!response.ok) {
            return { ok: false, status: response.status };
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        const filename = `products_${new Date().toISOString().slice(0, 10)}.csv`;
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        return { ok: true, status: 200, filename };
    } catch {
        return { ok: false, status: 0 };
    }
}

export const RETURN_KEY = 'tindaflow.returnTo';

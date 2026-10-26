import { failureMessage } from '../catalog/catalogApi';

/**
 * Downloads a CSV the API builds (`Accept: text/csv`) as a file. Resolves { ok: true, filename } or { ok: false, message }.
 * The server records BILLING_EXPORTED when it answers.
 */
export async function downloadCsv(path, filename) {
    let response;
    try {
        response = await fetch(path, { headers: { Accept: 'text/csv' }, credentials: 'same-origin' });
    } catch {
        return { ok: false, message: failureMessage({ status: 0 }, 'The export could not be made.') };
    }
    if (!response.ok) {
        return { ok: false, status: response.status, message: failureMessage({ status: response.status, body: null }, 'The export could not be made.') };
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    return { ok: true, filename };
}

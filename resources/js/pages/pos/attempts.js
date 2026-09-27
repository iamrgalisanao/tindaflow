/**
 * The retry-safety rules of the till's writes (see also api.js). Kept out of Pos.jsx so they can be tested without rendering the
 * till: a dropped connection after the server saved a sale must never end in a second sale.
 */

export function newIdempotencyKey() {
    return crypto.randomUUID();
}

/**
 * One Idempotency-Key per intent, not per click. The key is tied to the exact request it was made for: pressing the
 * button again after a dropped connection sends the SAME key with the same request, so if the server had already saved
 * it the answer is the first result and nothing is applied twice. Change anything in the request and the key changes
 * with it (the server refuses one key for two different requests).
 */
export function attemptKey(attempts, scope, path, body) {
    const fingerprint = `${path}\n${JSON.stringify(body ?? null)}`;
    const held = attempts.current[scope];
    if (held?.fingerprint === fingerprint) {
        return held.key;
    }
    const key = newIdempotencyKey();
    attempts.current[scope] = { fingerprint, key };

    return key;
}

/** Nothing arrived (0), a gateway answered with something that is not JSON (502), or the server could not finish (5xx, 409). */
export function outcomeUnknown(result) {
    return result.status === 0 || result.status >= 500 || result.status === 409;
}

/** A definite answer ends the attempt; an unknown outcome keeps the key so the next press is the same attempt. */
export function settleAttempt(attempts, scope, result) {
    if (!outcomeUnknown(result)) {
        delete attempts.current[scope];
    }
}

export const CONNECTION_DROPPED = 'The connection dropped, so this may already have been recorded. Press the button again: it will not be applied twice.';

export const SESSION_EXPIRED = 'Your session expired. Sign in again, then press the button again: nothing on this screen is lost.';

export function failureText(result, fallback) {
    if (result.status === 401) {
        return SESSION_EXPIRED;
    }

    return result.status === 0 || result.status === 502 ? CONNECTION_DROPPED : (result.body?.error?.message ?? fallback);
}

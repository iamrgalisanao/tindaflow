/**
 * How a payment method code (openapi PaymentMethod) is shown where the screens show the code itself: BANK_TRANSFER reads
 * "BANK TRANSFER", and every other code is already one word.
 */
export function methodText(method) {
    return String(method ?? '').replaceAll('_', ' ');
}

/** The non-cash methods a cashier may note a reference number for (ADR-016 section 4). Never required. */
export const REFERENCE_METHODS = ['GCASH', 'MAYA', 'BANK_TRANSFER'];

/** The longest reference the server keeps. */
export const REFERENCE_MAX_LENGTH = 255;

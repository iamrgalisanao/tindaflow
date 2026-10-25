/** Helpers for the employee screens (ADR-016 section 5). Pure, so they are unit tested without rendering. */

export const EMPLOYEE_TYPES = [
    { id: 'DIRECT', label: 'TSC (direct)', short: 'TSC', prefix: 'TSC' },
    { id: 'SUBCONTRACTOR', label: 'SUB (subcontractor)', short: 'SUB', prefix: 'SUB' },
];

export function typeLabel(type) {
    return EMPLOYEE_TYPES.find((entry) => entry.id === type)?.label ?? String(type ?? '');
}

export function typeShort(type) {
    return EMPLOYEE_TYPES.find((entry) => entry.id === type)?.short ?? String(type ?? '');
}

/** "First Middle Last" from the form's three boxes; blank parts are left out and spaces are tidied. */
export function joinName(first, middle, last) {
    return [first, middle, last]
        .map((part) => String(part ?? '').trim().replaceAll(/\s+/g, ' '))
        .filter(Boolean)
        .join(' ');
}

/** Client-side shape checks for the employee form; the server has the last word. */
export function validateEmployee(values, editing) {
    const errors = {};
    if (values.first_name.trim() === '') {
        errors.first_name = 'Enter a first name.';
    }
    if (values.last_name.trim() === '') {
        errors.last_name = 'Enter a last name.';
    }
    if (!editing && !EMPLOYEE_TYPES.some((entry) => entry.id === values.employee_type)) {
        errors.employee_type = 'Choose TSC (direct) or SUB (subcontractor).';
    }
    return errors;
}

/** The body for employeeCreate / employeeUpdate: the type goes only on a create, and a blank middle name is sent as null. */
export function employeeBody(values, editing) {
    const body = {
        first_name: values.first_name.trim(),
        middle_name: values.middle_name.trim() === '' ? null : values.middle_name.trim(),
        last_name: values.last_name.trim(),
    };
    return editing ? body : { employee_type: values.employee_type, ...body };
}

/**
 * A credit limit typed by a person: "1500", "1,500", "₱1,500.5" -> "1500.00". Returns null when it is not an amount of at most two
 * decimals (zero is allowed: no credit without a supervisor override).
 */
export function normalizeLimit(text) {
    const cleaned = String(text ?? '').trim().replace(/^₱/, '').replaceAll(',', '');
    if (!/^\d{1,9}(\.\d{1,2})?$/.test(cleaned)) {
        return null;
    }
    const [whole, decimals = ''] = cleaned.split('.');
    return `${whole.replace(/^0+(?=\d)/, '')}.${decimals.padEnd(2, '0')}`;
}

export function formatLimit(amount) {
    const [whole, decimals = '00'] = String(amount ?? '0').split('.');
    return `₱${whole.replaceAll(/\B(?=(\d{3})+(?!\d))/g, ',')}.${decimals.padEnd(2, '0')}`;
}

/** The query string of the list / ID-card screens from the filter state; blank filters are left out. */
export function employeeQuery({ search = '', type = '', status = 'all', page, perPage } = {}) {
    const params = new URLSearchParams();
    if (perPage) {
        params.set('per_page', String(perPage));
    }
    if (page) {
        params.set('page', String(page));
    }
    if (search.trim() !== '') {
        params.set('search', search.trim());
    }
    if (type) {
        params.set('employee_type', type);
    }
    if (status !== 'all') {
        params.set('active', status === 'active' ? 'true' : 'false');
    }
    return params.toString();
}

/** Splits cards into printed pages of `perPage` (a page of the sheet breaks after each chunk). */
export function chunkCards(cards, perPage = 8) {
    const pages = [];
    for (let index = 0; index < cards.length; index += perPage) {
        pages.push(cards.slice(index, index + perPage));
    }
    return pages;
}

/** One line for the import result headline. */
export function importHeadline(result, done) {
    const parts = [`${result.created} ${done ? 'created' : 'will be created'}`];
    if (result.skipped > 0) {
        parts.push(`${result.skipped} skipped as already enrolled`);
    }
    if (result.failed > 0) {
        parts.push(`${result.failed} with problems`);
    }
    return parts.join(', ');
}

export const IMPORT_TEMPLATE = 'first_name,middle_name,last_name,employee_type\nMaria,Reyes,Santos,TSC\nRamon,,Garcia,SUB\n';

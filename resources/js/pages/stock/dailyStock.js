import { fromThousandths, toThousandths } from '../pos/posMoney';

/**
 * Pure helpers for the daily stock grid (ADR-017 slice B): the morning screen where the day's cooked portions are entered, on the till (a cashier,
 * with a manager's code) and in the back office (a manager, directly). Quantities are whole thousandths (BigInt), never floating point. Nothing here
 * decides anything: the server turns the grid into added or removed portions and is the authority on every figure.
 */

export const rowKey = (row) => `${row.kind}:${row.id}`;

/** "20", "2.5" or "" -> thousandths; null when it is not a quantity with up to 3 decimals. */
export function parseQuantity(text) {
    const trimmed = String(text ?? '').trim();
    if (!/^\d{1,7}(\.\d{1,3})?$/.test(trimmed)) {
        return null;
    }

    return toThousandths(trimmed);
}

export const quantityText = (thousandths) => fromThousandths(thousandths);

/** Rows grouped by their dish category (plain daily products last under "Other items"), in the server's order. @returns {Array<{name: string, rows: object[]}>} */
export function groupRows(rows) {
    const groups = [];
    for (const row of rows) {
        const name = row.group ?? 'Other items';
        let group = groups.find((entry) => entry.name === name);
        if (!group) {
            group = { name, rows: [] };
            groups.push(group);
        }
        group.rows.push(row);
    }

    return groups;
}

/**
 * The lines the grid would save: a row whose "cooked today" box differs from what is already cooked sets the total; a row with something in
 * "add" adds portions (the second batch). A row with both is a mistake. Returns the request lines, whether any lowers a total, and per-row errors.
 *
 * @param {object[]} rows  the grid rows from GET /daily-stock
 * @param {{[key: string]: string}} cooked  the "cooked today" boxes the user typed in
 * @param {{[key: string]: string}} add  the "add portions" boxes
 */
export function buildLines(rows, cooked, add) {
    const lines = [];
    const errors = {};
    let lowers = false;
    for (const row of rows) {
        const key = rowKey(row);
        const idField = row.kind === 'DISH' ? { dish_id: row.id } : { product_id: row.id };
        const setText = (cooked[key] ?? '').trim();
        const addText = (add[key] ?? '').trim();
        const current = parseQuantity(row.cooked) ?? 0n;

        if (setText !== '' && addText !== '') {
            errors[key] = 'Use either the total cooked or the portions to add, not both.';
            continue;
        }
        if (setText !== '') {
            const target = parseQuantity(setText);
            if (target === null) {
                errors[key] = 'Enter a number of portions, up to 3 decimals.';
            } else if (target !== current) {
                lines.push({ ...idField, cooked: setText });
                lowers = lowers || target < current;
            }
        } else if (addText !== '') {
            const more = parseQuantity(addText);
            if (more === null || more <= 0n) {
                errors[key] = 'Enter portions to add, above zero.';
            } else {
                lines.push({ ...idField, add: addText });
            }
        }
    }

    return { lines, lowers, errors };
}

/** "Copy yesterday's quantities": the boxes for every row that has nothing cooked today yet and had something yesterday. Nothing is saved by this. */
export function copyYesterday(rows) {
    const boxes = {};
    for (const row of rows) {
        const today = parseQuantity(row.cooked) ?? 0n;
        const yesterday = parseQuantity(row.yesterday_cooked) ?? 0n;
        if (today === 0n && yesterday > 0n) {
            boxes[rowKey(row)] = quantityText(yesterday);
        }
    }

    return boxes;
}

/** What the manager reads after the approval request failed. Never says why a code failed beyond "did not work", nor how many tries are left. */
export function approvalFailureText(result) {
    const code = result?.body?.error?.code;
    if (code === 'STOCK_ENTRY_APPROVAL_INVALID') {
        return 'That code did not work.';
    }
    if (code === 'STOCK_ENTRY_APPROVAL_LOCKED') {
        return result.body.error.message ?? 'Too many wrong codes. This manager is locked for a while. Ask another manager, or wait.';
    }
    if (code === 'STOCK_ENTRY_APPROVAL_NOT_ALLOWED' || code === 'STOCK_ENTRY_APPROVAL_EXPIRED') {
        return result.body.error.message;
    }
    if (result?.status === 429) {
        return 'Too many tries from this till. Wait a few minutes, then try again.';
    }
    if (result?.status === 401) {
        return 'Your session expired. Sign in again.';
    }
    if (result?.status === 0 || result?.status === 502) {
        return 'The connection dropped. Nothing was approved. Try again.';
    }

    return result?.body?.error?.message ?? 'The approval could not be checked. Try again.';
}

/** The sentence under the grid after a save, or the reason it did not happen. */
export function saveFailureText(result) {
    const error = result?.body?.error;
    if (error?.code === 'DAILY_STOCK_BELOW_SOLD') {
        const names = (error.details?.items ?? []).map((item) => item.name).join(', ');

        return `${names || 'An item'} cannot go below what is already sold today. Nothing was saved.`;
    }
    if (String(error?.code ?? '').startsWith('STOCK_ENTRY_APPROVAL_')) {
        return error.message ?? 'The manager approval was not accepted. Ask for it again.';
    }
    if (result?.status === 401) {
        return 'Your session expired. Sign in again.';
    }
    if (result?.status === 403) {
        return 'Your role does not allow this. A manager must approve with their code.';
    }
    if (result?.status === 0 || result?.status === 502) {
        return 'The connection dropped. Nothing is lost: save again and it will not be counted twice.';
    }

    return error?.message ?? 'The stock could not be saved. Try again.';
}

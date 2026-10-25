/**
 * Helpers for the menu-group tree (ADR-016 slice 1.1) shared by the Menu groups screen and the product form.
 * Rows are what GET /api/v1/menu-groups returns: { id, parent_id, name, sort_order, active }.
 */

/** Top groups in order, each with its sub-groups in order. Sub-groups of an unknown parent are ignored. */
export function groupTree(rows) {
    const byOrder = (a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name);
    return rows
        .filter((row) => !row.parent_id)
        .sort(byOrder)
        .map((top) => ({ ...top, children: rows.filter((row) => row.parent_id === top.id).sort(byOrder) }));
}

/**
 * Flat, ordered choices for a product's "Menu group" select: "Lunch", "Lunch › Standard Meal". A hidden group is still listed
 * (marked) so a product that already sits in it shows where it is.
 *
 * @returns {Array<{id: string, label: string}>}
 */
export function menuGroupOptions(rows) {
    const mark = (row, label) => (row.active ? label : `${label} (hidden)`);
    return groupTree(rows).flatMap((top) => [
        { id: top.id, label: mark(top, top.name) },
        ...top.children.map((child) => ({ id: child.id, label: mark(child, `${top.name} › ${child.name}`) })),
    ]);
}

/** "Lunch › Standard Meal" for a group id, or null when it is in no group (or the group is unknown). */
export function groupPathLabel(rows, id) {
    const row = rows.find((candidate) => candidate.id === id);
    if (!row) {
        return null;
    }
    const parent = row.parent_id ? rows.find((candidate) => candidate.id === row.parent_id) : null;
    return parent ? `${parent.name} › ${row.name}` : row.name;
}

/** The ids with `id` moved one place up (-1) or down (+1); unchanged at either end. */
export function moveId(ids, id, direction) {
    const from = ids.indexOf(id);
    const to = from + direction;
    if (from === -1 || to < 0 || to >= ids.length) {
        return ids;
    }
    const next = [...ids];
    [next[from], next[to]] = [next[to], next[from]];
    return next;
}

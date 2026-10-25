/**
 * The till's menu-group tabs (ADR-016 slice 1.1). The server already returns only the groups the till may show (active, and
 * not under a hidden parent); this keeps the shape defensive anyway: inactive rows are dropped, a sub-group whose parent is
 * not in the list is dropped, and depth beyond two levels is ignored.
 *
 * @param {Array<{id: string, parent_id: string|null, name: string, sort_order: number, active: boolean}>} rows
 * @returns {Array<{id: string, name: string, children: Array<{id: string, name: string}>}>} top groups in till order
 */
export function buildMenuTabs(rows) {
    const active = (Array.isArray(rows) ? rows : []).filter((row) => row && row.active !== false);
    const byOrder = (a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || String(a.name).localeCompare(String(b.name));
    const tops = active.filter((row) => !row.parent_id).sort(byOrder);

    return tops.map((top) => ({
        id: top.id,
        name: top.name,
        children: active
            .filter((row) => row.parent_id === top.id)
            .sort(byOrder)
            .map((child) => ({ id: child.id, name: child.name })),
    }));
}

/**
 * The `/api/v1/products` filter for the selected tabs: the sub-group when one is chosen, otherwise the top group (the server
 * includes the top group's sub-groups), otherwise none.
 */
export function groupFilter(groupId, subGroupId) {
    const id = subGroupId || groupId;
    return id ? `&menu_group_id=${encodeURIComponent(id)}` : '';
}

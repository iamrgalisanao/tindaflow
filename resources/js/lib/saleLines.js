/**
 * The name to show for a sale line. An add-on line (rice with a meal) carries parent_line_number, the line_number of the meal
 * line it belongs to, so two plates' rice read "Whole rice (for Standard Meal - Beef nilaga)" and "... Pork adobo)" instead of
 * identically. Every other line, and an add-on whose meal line is not in the list, shows its own name unchanged.
 *
 * @param {{product_name_snapshot: string, parent_line_number?: number|null}} item
 * @param {Array<{line_number: number, product_name_snapshot: string}>} items every line of the same sale
 * @returns {string}
 */
export function saleLineName(item, items) {
    if (item.parent_line_number === null || item.parent_line_number === undefined) {
        return item.product_name_snapshot;
    }
    const parent = items.find((candidate) => candidate.line_number === item.parent_line_number);
    return parent ? `${item.product_name_snapshot} (for ${parent.product_name_snapshot})` : item.product_name_snapshot;
}

/**
 * The two conversions a pack receipt shows a preview of, in exact integer arithmetic (BigInt), mirroring the server's
 * App\Services\Catalog\PackagingMath. The server does the real conversion and is the only authority; this exists so the
 * person sees "= 1,200 units at ₱4.81" before they press the button.
 *
 *   units      = packs x units per pack, which must be a whole number of thousandths of a unit (nothing is rounded)
 *   unit cost  = pack cost / units per pack, rounded half up to the centavo
 *
 * Quantities are decimal text with up to three places; money is decimal text with exactly two.
 */

const MAX_UNITS_THOUSANDTHS = 9999999999n; // 9,999,999.999, the ledger's NUMERIC(10,3)

function thousandths(text) {
    const [whole, fraction = ''] = String(text).split('.');
    return BigInt(whole) * 1000n + BigInt(`${fraction}000`.slice(0, 3));
}

function cents(money) {
    const [whole, fraction = ''] = String(money).split('.');
    return BigInt(whole) * 100n + BigInt(`${fraction}00`.slice(0, 2));
}

/** @returns {string|null} units as "120.000", or null when the count is not exact or is more than the ledger holds */
export function packUnits(packs, unitsPerPack) {
    const product = thousandths(packs) * thousandths(unitsPerPack);
    if (product % 1000n !== 0n) {
        return null;
    }
    const total = product / 1000n;
    if (total <= 0n || total > MAX_UNITS_THOUSANDTHS) {
        return null;
    }
    return `${total / 1000n}.${String(total % 1000n).padStart(3, '0')}`;
}

/** @returns {string} the unit cost as "4.81", half up, from the cost of one pack ("1153.92") */
export function packUnitCost(packCost, unitsPerPack) {
    const units = thousandths(unitsPerPack);
    const unitCents = (cents(packCost) * 1000n * 2n + units) / (2n * units);
    return `${unitCents / 100n}.${String(unitCents % 100n).padStart(2, '0')}`;
}

/** True when the pack cost divides into a whole number of centavos per unit, so nothing is lost by rounding. */
export function packCostDividesExactly(packCost, unitsPerPack) {
    return (cents(packCost) * 1000n) % thousandths(unitsPerPack) === 0n;
}

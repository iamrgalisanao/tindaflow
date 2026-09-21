<?php

namespace App\Services\Catalog;

/**
 * The two conversions a pack receipt needs, in exact decimal arithmetic (never floats):
 *
 *   units      = packs x units_per_base, which must be EXACT to the ledger's three decimals (a pack count that does not
 *              come out as a whole number of thousandths of a unit would silently lose stock, so it is refused);
 *   unit cost  = pack cost / units_per_base, rounded half up to the two decimals the ledger stores.
 *
 * The rounded unit cost cannot always give the pack cost back (1153.92 / 240 = 4.808, stored as 4.81, and 240 x 4.81 is
 * 1154.40), which is why the receipt also records the packs, the pack size and the pack cost themselves: the pack cost is
 * the truth and the unit cost is derived. docs/06-backend/stage-29-packaging-and-pack-receiving.md.
 */
final class PackagingMath
{
    /** Largest quantity the ledger holds: NUMERIC(10,3). */
    private const MAX_UNITS = '9999999.999';

    /** @return string|null the units as a 3-decimal string, or null when the result is not exact or does not fit */
    public static function units(string $packs, string $unitsPerBase): ?string
    {
        $exact = bcmul($packs, $unitsPerBase, 6);

        if (bccomp($exact, bcadd($exact, '0', 3), 6) !== 0 || bccomp($exact, self::MAX_UNITS, 6) > 0) {
            return null;
        }

        return bcadd($exact, '0', 3);
    }

    /** The unit cost, half up to two decimals, from the cost of one pack. */
    public static function unitCost(string $packCost, string $unitsPerBase): string
    {
        return bcadd(bcdiv(bcadd(bcdiv($packCost, $unitsPerBase, 8), '0.005', 8), '1', 8), '0', 2);
    }
}

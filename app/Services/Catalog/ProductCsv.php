<?php

namespace App\Services\Catalog;

/**
 * The product CSV layout shared by productExport and productImport, so a file exported from the catalog
 * can be edited and imported straight back (docs/06-backend/stage-21-product-csv.md).
 *
 * Text cells that a spreadsheet would read as a formula (a leading `=`, `+`, `-`, `@`, tab or carriage
 * return) are written with a leading apostrophe so opening the export in Excel can never run one, and the
 * import removes that apostrophe again. The pair is exactly reversible: a value that already begins with
 * apostrophes followed by such a character simply gets one more.
 */
final class ProductCsv
{
    /** The pinned column order. New columns may only be appended (csv-export-contract.md). */
    public const COLUMNS = [
        'sku', 'barcode', 'name', 'description', 'category', 'brand', 'unit_of_measure',
        'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level', 'active',
    ];

    /** Columns whose cells are free text and so need the formula guard. */
    public const TEXT_COLUMNS = ['sku', 'barcode', 'name', 'description', 'category', 'brand', 'unit_of_measure'];

    /** A formula character, possibly behind apostrophes already. Guard and unguard cover the same values, so they reverse exactly. */
    private const FORMULA_START = '/^\'*[=+\-@\t\r]/';

    private const GUARDED = '/^\'+[=+\-@\t\r]/';

    public static function guard(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match(self::FORMULA_START, $value) === 1 ? "'".$value : $value;
    }

    public static function unguard(string $value): string
    {
        return preg_match(self::GUARDED, $value) === 1 ? substr($value, 1) : $value;
    }
}

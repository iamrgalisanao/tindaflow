<?php

namespace App\Enums;

use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 7: which document a store gives for a sale. INVOICE is the default and the whole existing fiscal path; SLIP
 * is the internal-monitoring path (no fiscal installation, invoice series, invoice row or electronic journal entry).
 */
enum SaleDocumentMode: string
{
    case Invoice = 'INVOICE';
    case Slip = 'SLIP';

    /** The store's current mode. A store with no settings row yet is in INVOICE mode, exactly as every store was before this existed. */
    public static function forStore(string $storeId): self
    {
        $value = DB::table('store_settings')->where('store_id', $storeId)->value('sale_document_mode');

        return $value === null ? self::Invoice : self::from($value);
    }

    public function isSlip(): bool
    {
        return $this === self::Slip;
    }
}

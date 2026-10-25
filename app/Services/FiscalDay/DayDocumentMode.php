<?php

namespace App\Services\FiscalDay;

use App\Enums\SaleDocumentMode;
use App\Models\Sale;

/**
 * ADR-016 section 7: in SLIP mode the "fiscal day" is the business day and its Z-reading is the internal end-of-day report. Which of
 * the two a given day was is a fact about its sales: the store's mode cannot change while a day is open, so every sale of a day carries
 * the same `document_mode`. A day with no sale at all has nothing to label and follows the store's current mode.
 */
final class DayDocumentMode
{
    public static function of(string $fiscalDayId, string $storeId): SaleDocumentMode
    {
        $mode = Sale::where('fiscal_day_id', $fiscalDayId)->orderBy('sold_at')->value('document_mode');

        return $mode === null ? SaleDocumentMode::forStore($storeId) : SaleDocumentMode::from($mode);
    }
}

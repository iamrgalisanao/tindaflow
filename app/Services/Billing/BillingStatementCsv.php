<?php

namespace App\Services\Billing;

/**
 * The CSV of a billing statement (docs/05-api/csv-export-contract.md, "Billing statement"). One flat table: an ITEM row for every
 * employee / meal type / item, a MEAL_TYPE_TOTAL row per meal type, an EMPLOYEE_TOTAL row per employee, one ADJUSTMENT row per void or
 * refund line (amounts negative, so the file also adds up), and a GRAND_TOTAL row. Money is the API's two-decimal string, never
 * reformatted. A text cell that starts with = + - or @ gets a leading apostrophe so a spreadsheet cannot read a name as a formula.
 */
final class BillingStatementCsv
{
    public const COLUMNS = [
        'row_type', 'employee_code', 'employee_name', 'employee_type', 'meal_type', 'item', 'item_kind',
        'quantity_sold', 'amount_sold', 'quantity_returned', 'amount_returned', 'quantity_net', 'amount_net',
        'adjustment_type', 'adjustment_date', 'sold_on', 'document_reference', 'reason',
    ];

    /** @param  array<string, mixed>  $statement */
    public function render(array $statement): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, self::COLUMNS);

        $adjustmentsByEmployee = [];
        foreach ($statement['adjustments'] as $adjustment) {
            $adjustmentsByEmployee[$adjustment['employee_id']][] = $adjustment;
        }

        foreach ($statement['accounts'] as $account) {
            $who = ['employee_code' => $account['employee_code'], 'employee_name' => $account['employee_name'], 'employee_type' => $account['employee_type']];
            foreach ($account['meal_types'] as $type) {
                foreach ($type['items'] as $item) {
                    $this->row($handle, $who + ['row_type' => 'ITEM', 'meal_type' => $type['meal_type'], 'item' => $item['item'], 'item_kind' => $item['kind'],
                        'quantity_sold' => $item['quantity_sold'], 'amount_sold' => $item['sold_amount'],
                        'quantity_returned' => $item['quantity_returned'], 'amount_returned' => $item['returned_amount'],
                        'quantity_net' => $item['quantity_net'], 'amount_net' => $item['net_amount']]);
                }
                $this->row($handle, $who + ['row_type' => 'MEAL_TYPE_TOTAL', 'meal_type' => $type['meal_type'],
                    'amount_sold' => $type['sold_amount'], 'amount_returned' => $type['returned_amount'], 'amount_net' => $type['net_amount']]);
            }
            foreach ($adjustmentsByEmployee[$account['employee_id']] ?? [] as $adjustment) {
                foreach ($adjustment['lines'] as $line) {
                    $this->row($handle, $who + ['row_type' => 'ADJUSTMENT', 'meal_type' => $line['meal_type'], 'item' => $line['item'], 'item_kind' => $line['kind'],
                        'quantity_returned' => $line['quantity'], 'amount_returned' => $line['amount'],
                        'quantity_net' => '-'.$line['quantity'], 'amount_net' => '-'.$line['amount'],
                        'adjustment_type' => $adjustment['type'], 'adjustment_date' => $adjustment['recorded_at'], 'sold_on' => $adjustment['sold_on'],
                        'document_reference' => $adjustment['document_reference'], 'reason' => $adjustment['reason']]);
                }
            }
            $this->row($handle, $who + ['row_type' => 'EMPLOYEE_TOTAL', 'amount_sold' => $account['sold_amount'], 'amount_returned' => $account['returned_amount'], 'amount_net' => $account['net_amount']]);
        }

        $this->row($handle, ['row_type' => 'GRAND_TOTAL', 'amount_sold' => $statement['totals']['sold_amount'], 'amount_returned' => $statement['totals']['returned_amount'], 'amount_net' => $statement['totals']['net_amount']]);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $row
     */
    private function row($handle, array $row): void
    {
        $text = ['employee_code', 'employee_name', 'meal_type', 'item', 'reason', 'document_reference'];
        fputcsv($handle, array_map(function (string $column) use ($row, $text) {
            $value = (string) ($row[$column] ?? '');

            return in_array($column, $text, true) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
        }, self::COLUMNS));
    }
}

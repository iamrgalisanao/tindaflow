<?php

namespace App\Services\Billing;

use App\Models\BillingCheque;
use Illuminate\Support\Collection;

/**
 * CSV of the cheque list and of the balance by period (docs/05-api/csv-export-contract.md, "Billing cheques" and "Billing balance"). Slice 4.3
appended `written_off_amount` and `write_off_excess_amount` to the balance columns; the earlier columns keep their order.
 * Money is the API's two-decimal string. A text cell starting with = + - or @ gets a leading apostrophe so a spreadsheet cannot read a
 * bank name or note as a formula.
 */
final class BillingChequeCsv
{
    public const CHEQUE_COLUMNS = ['cheque_number', 'bank', 'cheque_date', 'received_on', 'amount', 'status', 'periods_paid', 'returned_on', 'return_reason', 'notes', 'received_by'];

    public const BALANCE_COLUMNS = ['row_type', 'period_start', 'period_end', 'billed_amount', 'received_amount', 'owed_amount', 'status', 'written_off_amount', 'write_off_excess_amount'];

    /** @param  Collection<int, BillingCheque>  $cheques */
    public function renderCheques(Collection $cheques): string
    {
        return $this->build(self::CHEQUE_COLUMNS, $cheques->map(fn (BillingCheque $cheque) => [
            'cheque_number' => $cheque->cheque_number,
            'bank' => $cheque->bank,
            'cheque_date' => $cheque->cheque_date->toDateString(),
            'received_on' => $cheque->received_on->toDateString(),
            'amount' => (string) $cheque->amount,
            'status' => $cheque->status,
            'periods_paid' => $cheque->allocations->map(fn ($a) => $a->period_start->toDateString().' '.$a->amount)->implode('; '),
            'returned_on' => $cheque->returned_on?->toDateString(),
            'return_reason' => $cheque->return_reason,
            'notes' => $cheque->notes,
            'received_by' => $cheque->receiver?->name,
        ])->all(), ['cheque_number', 'bank', 'return_reason', 'notes', 'received_by']);
    }

    /** @param  array<string, mixed>  $balance */
    public function renderBalance(array $balance): string
    {
        $rows = array_map(fn (array $period) => [
            'row_type' => 'PERIOD',
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'billed_amount' => $period['billed_amount'],
            'received_amount' => $period['received_amount'],
            'owed_amount' => $period['owed_amount'],
            'status' => $period['status'],
            'written_off_amount' => $period['written_off_amount'],
            'write_off_excess_amount' => $period['write_off_excess_amount'],
        ], $balance['periods']);
        $totals = $balance['totals'];
        $rows[] = ['row_type' => 'TOTAL', 'billed_amount' => $totals['billed_amount'], 'received_amount' => $totals['received_amount'], 'owed_amount' => $totals['owed_amount'], 'written_off_amount' => $totals['written_off_amount'], 'write_off_excess_amount' => $totals['write_off_excess_amount']];
        $rows[] = ['row_type' => 'CREDIT_TO_PARTY', 'owed_amount' => $totals['credit_amount']];

        return $this->build(self::BALANCE_COLUMNS, $rows, []);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $textColumns
     */
    private function build(array $columns, array $rows, array $textColumns): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(function (string $column) use ($row, $textColumns) {
                $value = (string) ($row[$column] ?? '');

                return in_array($column, $textColumns, true) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
            }, $columns));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}

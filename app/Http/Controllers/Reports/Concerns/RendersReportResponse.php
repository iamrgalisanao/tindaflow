<?php

namespace App\Http\Controllers\Reports\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml ReportOk / ReportResult -- shared across all 15 Reports
 * operations. `Accept: text/csv` (docs/05-api/csv-export-contract.md)
 * gets the pinned column layout as RFC 4180 CSV; anything else
 * (including the default) gets ReportResult JSON. Money/quantity values
 * are expected to already be decimal strings by the time they reach
 * this trait -- it never rounds or reformats them.
 */
trait RendersReportResponse
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $csvColumns  exact order per csv-export-contract.md
     */
    protected function reportResponse(Request $request, array $filters, array $rows, array $summary, array $csvColumns): Response
    {
        if ($this->wantsCsv($request)) {
            return $this->csvResponse($csvColumns, $rows);
        }

        return response()->json([
            'generated_at' => Carbon::now()->toJSON(),
            'filters_applied' => (object) $filters,
            'rows' => array_values($rows),
            'summary' => (object) $summary,
        ]);
    }

    protected function wantsCsv(Request $request): bool
    {
        return $request->header('Accept') === 'text/csv';
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function csvResponse(array $columns, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column) => $row[$column] ?? '',
                $columns,
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200)->header('Content-Type', 'text/csv; charset=UTF-8');
    }

    /** Inclusive [from, to] window per FromParam/ToParam -- either bound may be omitted (no lower/upper bound). An unparsable date is silently ignored rather than rejected -- the frozen contract declares no 422 for any Reports operation. */
    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function fromDate(Request $request): ?Carbon
    {
        return $this->parseDate($request->query('from'));
    }

    protected function toDate(Request $request): ?Carbon
    {
        return $this->parseDate($request->query('to'))?->endOfDay();
    }
}

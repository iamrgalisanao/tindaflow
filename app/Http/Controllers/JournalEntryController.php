<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Resources\ElectronicJournalEntryResource;
use App\Models\ElectronicJournalEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml journalEntryList (JOURNAL_VIEW, session only). Scoped to the actor's store, newest first.
 * `Accept: text/csv` returns the whole filtered set in the pinned column layout (csv-export-contract.md:
 * `occurred_at, event_type, source_type, source_id, terminal_id, audit_event_id`); the paging parameters
 * apply to the JSON form only. The CSV is streamed from a cursor so a long journal is never held in memory.
 *
 * journalEntryGet is deliberately not implemented yet, for the same reason as auditEventGet: its 404 has
 * no registered error code. See AuditEventController.
 */
class JournalEntryController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    private const EVENT_TYPES = ['INVOICE', 'VOID', 'REFUND', 'X_READING', 'Z_READING', 'SHIFT_OPENED', 'SHIFT_CLOSED', 'CASH_IN', 'CASH_OUT', 'STOCK_ADJUSTED'];

    private const CSV_COLUMNS = ['occurred_at', 'event_type', 'source_type', 'source_id', 'terminal_id', 'audit_event_id'];

    public function list(Request $request): Response
    {
        $query = $this->filteredQuery($request)->orderByDesc('occurred_at')->orderByDesc('id');

        if ($request->header('Accept') === 'text/csv') {
            return $this->csv($query);
        }

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            ElectronicJournalEntryResource::class,
        );
    }

    /** @return Builder<ElectronicJournalEntry> */
    private function filteredQuery(Request $request): Builder
    {
        $actor = Auth::guard('web')->user();
        $query = ElectronicJournalEntry::query()->where('store_id', $actor->store_id);

        if ($request->filled('event_type')) {
            $this->whereOneOf($query, 'event_type', (string) $request->query('event_type'), self::EVENT_TYPES);
        }
        if ($request->filled('terminal_id')) {
            $this->whereUuid($query, 'terminal_id', (string) $request->query('terminal_id'));
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('occurred_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('occurred_at', '<=', $to->endOfDay());
        }

        return $query;
    }

    /** @param  Builder<ElectronicJournalEntry>  $query */
    private function csv(Builder $query): Response
    {
        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, self::CSV_COLUMNS);
            foreach ($query->cursor() as $entry) {
                fputcsv($out, [
                    $entry->occurred_at?->toIso8601String(),
                    $entry->event_type,
                    $entry->source_type,
                    $entry->source_id,
                    $entry->terminal_id,
                    $entry->audit_event_id,
                ]);
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

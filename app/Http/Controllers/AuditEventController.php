<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml auditEventList (AUDIT_VIEW, session only). Scoped to the actor's store, newest first.
 * A filter value that cannot match anything matches nothing.
 *
 * auditEventGet is deliberately not implemented yet: its 404 has no registered error code, so completing
 * it is the "gap in an already-frozen response" case that error-catalog.md's governance note reserves for
 * an owner decision. Nothing needs it: a list row already carries the whole event.
 */
class AuditEventController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $query = AuditEvent::query()->where('store_id', $actor->store_id);

        if ($request->filled('event_type')) {
            $query->where('event_type', (string) $request->query('event_type'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', (string) $request->query('entity_type'));
        }
        foreach (['actor_user_id', 'entity_id'] as $column) {
            if ($request->filled($column)) {
                $this->whereUuid($query, $column, (string) $request->query($column));
            }
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('occurred_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('occurred_at', '<=', $to->endOfDay());
        }

        return $this->paginatedResponse(
            $query->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            AuditEventResource::class,
        );
    }
}

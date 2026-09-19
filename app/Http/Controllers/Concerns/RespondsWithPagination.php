<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/** openapi.yaml PageParam/PerPageParam (per_page 1..100, default 25) and the PaginatedResponse envelope. */
trait RespondsWithPagination
{
    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }

    /** @param  class-string<JsonResource>  $resource */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}

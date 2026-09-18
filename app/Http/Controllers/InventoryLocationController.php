<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateInventoryLocationRequest;
use App\Http\Requests\UpdateInventoryLocationRequest;
use App\Http\Resources\InventoryLocationResource;
use App\Models\InventoryLocation;
use App\Services\StoreSetup\InventoryLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * New forward-committed admin surface (no prior openapi.yaml draft --
 * see docs/06-ui/stage-8-store-setup.md). FISCAL_CONFIGURATION_MANAGE.
 */
class InventoryLocationController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $paginator = InventoryLocation::where('store_id', $actor->store_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(
                perPage: (int) $request->query('per_page', 25),
                page: (int) $request->query('page', 1),
            );

        return response()->json([
            'data' => InventoryLocationResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function create(CreateInventoryLocationRequest $request, InventoryLocationService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $location = $service->create($actor->store_id, $request->validated('name'), (bool) $request->validated('is_default', false));

        return (new InventoryLocationResource($location))->response()->setStatusCode(201);
    }

    public function update(UpdateInventoryLocationRequest $request, InventoryLocationService $service, string $inventoryLocationId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $location = $service->update($actor->store_id, $inventoryLocationId, $request->validated());

        return (new InventoryLocationResource($location))->response();
    }
}

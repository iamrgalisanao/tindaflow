<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\CreateCatalogNameRequest;
use App\Http\Resources\CatalogNameResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml brandList (any authenticated user) / brandCreate (CATALOG_MANAGE). Same shape and
 * same contract limits as categories: no update, delete or name-uniqueness rule is defined.
 */
class BrandController extends Controller
{
    use RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return $this->paginatedResponse(
            Brand::where('store_id', $actor->store_id)->orderBy('name')->orderBy('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            CatalogNameResource::class,
        );
    }

    public function create(CreateCatalogNameRequest $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $brand = Brand::create(['store_id' => $actor->store_id, 'name' => $request->validated('name')]);

        return (new CatalogNameResource($brand))->response()->setStatusCode(201);
    }
}

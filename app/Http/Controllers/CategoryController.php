<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\CreateCatalogNameRequest;
use App\Http\Resources\CatalogNameResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml categoryList (any authenticated user) / categoryCreate (CATALOG_MANAGE). The contract
 * defines no update or delete for categories and no uniqueness rule for the name, so none is added.
 */
class CategoryController extends Controller
{
    use RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return $this->paginatedResponse(
            Category::where('store_id', $actor->store_id)->orderBy('name')->orderBy('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            CatalogNameResource::class,
        );
    }

    public function create(CreateCatalogNameRequest $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $category = Category::create(['store_id' => $actor->store_id, 'name' => $request->validated('name')]);

        return (new CatalogNameResource($category))->response()->setStatusCode(201);
    }
}

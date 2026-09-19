<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\ProductInputRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * openapi.yaml Catalog tag -- productList/Get (any authenticated user, no terminal credential:
 * operation-inventory.md "session" auth) and productCreate/Update/Activate/Deactivate
 * (CATALOG_MANAGE). Everything is scoped to the actor's own store_id (domain-model.md SS2.1);
 * a product in another store is indistinguishable from one that does not exist.
 *
 * Not implemented here: productLookupByBarcode (POS-side), productImport, productExport.
 */
class ProductController extends Controller
{
    use RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = Product::where('store_id', $actor->store_id);

        if ($request->filled('category_id')) {
            // A value that is not a UUID can match no row; comparing it to a uuid column is a database error.
            Str::isUuid((string) $request->query('category_id'))
                ? $query->where('category_id', $request->query('category_id'))
                : $query->whereRaw('1 = 0');
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }

        $sort = (string) $request->query('sort', 'name');
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'sku', 'created_at'], true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }
        $query->orderBy('id');

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            ProductResource::class,
        );
    }

    public function get(ProductService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductResource($service->find($actor->store_id, $productId)))->response();
    }

    public function create(ProductInputRequest $request, ProductService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductResource($service->create($actor->store_id, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(ProductInputRequest $request, ProductService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductResource($service->update($actor->store_id, $productId, $request->validated())))->response();
    }

    public function activate(ProductService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductResource($service->setActive($actor->store_id, $productId, true)))->response();
    }

    public function deactivate(ProductService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductResource($service->setActive($actor->store_id, $productId, false)))->response();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Catalog tag -- productList only (the minimal read-only
 * slice needed for the POS cart screen to browse/search products;
 * productCreate/productGet/etc. remain out of scope, per
 * stage-7-frontend-initialization.md's own discipline). No x-capability,
 * no terminal credential required (operation-inventory.md: "session"
 * auth, "Term. enrolled? = false") -- any authenticated user may browse
 * the catalog. Scoped to the actor's own store_id, matching
 * domain-model.md SS2.1's universal store-scoping convention.
 */
class ProductController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = Product::where('store_id', $actor->store_id);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
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

        $paginator = $query->paginate(
            perPage: (int) $request->query('per_page', 25),
            page: (int) $request->query('page', 1),
        );

        return response()->json([
            'data' => ProductResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}

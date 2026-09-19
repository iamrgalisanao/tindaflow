<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\BarcodeNotFoundException;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\ProductInputRequest;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Catalog\ProductCsv;
use App\Services\Catalog\ProductImportService;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml Catalog tag -- productList/Get (any authenticated user, no terminal credential:
 * operation-inventory.md "session" auth) and productCreate/Update/Activate/Deactivate
 * (CATALOG_MANAGE). Everything is scoped to the actor's own store_id (domain-model.md SS2.1);
 * a product in another store is indistinguishable from one that does not exist.
 *
 * productExport (any authenticated user, like productList) and productImport (CATALOG_MANAGE) use the CSV
 * layout in ProductCsv; docs/06-backend/stage-21-product-csv.md.
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

    /**
     * openapi.yaml productLookupByBarcode: what a cashier's scanner calls. Exact match on the product's own
     * barcode or on an alternate `product_barcodes` row, both unique per store, so at most one product can
     * match. Scanners often append a line break, so surrounding whitespace is ignored. An inactive product
     * is returned as it is (`active: false`) rather than hidden, so the till can say why it cannot be sold
     * instead of reporting an unknown barcode. The price and tax class are a preview only: checkout
     * recomputes everything itself.
     */
    public function lookupByBarcode(string $barcode): JsonResponse
    {
        $barcode = trim($barcode);
        $storeId = Auth::guard('web')->user()->store_id;

        $product = $barcode === '' ? null : Product::where('store_id', $storeId)
            ->where(fn ($query) => $query
                ->where('barcode', $barcode)
                ->orWhereIn('id', ProductBarcode::where('store_id', $storeId)->where('barcode', $barcode)->select('product_id')))
            ->first();

        return (new ProductResource($product ?? throw BarcodeNotFoundException::forBarcode($barcode)))->response();
    }

    /**
     * openapi.yaml productExport: the whole catalog, active and inactive, in the ProductCsv column order, so the
     * file can be edited and imported straight back. Streamed from a cursor; ordered by SKU. Category and brand
     * are written as names, which is what people can read and what the import matches on.
     */
    public function export(): Response
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $categories = Category::where('store_id', $storeId)->pluck('name', 'id');
        $brands = Brand::where('store_id', $storeId)->pluck('name', 'id');
        $query = Product::where('store_id', $storeId)->orderBy('sku')->orderBy('id');

        return response()->stream(function () use ($query, $categories, $brands) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ProductCsv::COLUMNS, ',', '"', '');
            foreach ($query->cursor() as $product) {
                fputcsv($out, [
                    ProductCsv::guard($product->sku),
                    ProductCsv::guard($product->barcode),
                    ProductCsv::guard($product->name),
                    ProductCsv::guard($product->description),
                    ProductCsv::guard($categories[$product->category_id] ?? null),
                    ProductCsv::guard($brands[$product->brand_id] ?? null),
                    ProductCsv::guard($product->unit_of_measure),
                    $product->cost,
                    $product->selling_price,
                    $product->tax_class,
                    $product->track_inventory ? 'true' : 'false',
                    $product->reorder_level,
                    $product->active ? 'true' : 'false',
                ], ',', '"', '');
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * openapi.yaml productImport: the request body is the CSV itself. Answers 202 with the ImportResult
     * (created / updated / failed / errors, plus `unchanged`). `?dry_run=true` reports what an import would do
     * and changes nothing.
     */
    public function import(Request $request, ProductImportService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return response()->json($service->import($actor->store_id, $request->getContent(), $request->boolean('dry_run')), 202);
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

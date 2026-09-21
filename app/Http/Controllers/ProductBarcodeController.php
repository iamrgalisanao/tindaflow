<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\AddProductBarcodeRequest;
use App\Http\Resources\ProductBarcodeResource;
use App\Services\Catalog\ProductBarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Catalog tag, alternate barcodes (stage 26, forward-committed). Reading needs only the session, like the
 * other catalog reads; adding and removing are CATALOG_MANAGE. Store-scoped through the product: another store's
 * product is PRODUCT_NOT_FOUND. A code already in use is the catalog's usual VALIDATION_FAILED field error.
 */
class ProductBarcodeController extends Controller
{
    use RespondsWithPagination;

    public function list(Request $request, ProductBarcodeService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $rows = $service->list($actor->store_id, $productId);

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
        );

        return $this->paginatedResponse($paginator, ProductBarcodeResource::class);
    }

    public function create(AddProductBarcodeRequest $request, ProductBarcodeService $service, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new ProductBarcodeResource($service->add($actor, $productId, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function delete(ProductBarcodeService $service, string $productId, string $barcodeId): Response
    {
        $service->remove(Auth::guard('web')->user(), $productId, $barcodeId);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateInvoiceSeriesRequest;
use App\Http\Resources\InvoiceSeriesResource;
use App\Models\InvoiceSeries;
use App\Services\StoreSetup\InvoiceSeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * New forward-committed admin surface (no prior openapi.yaml draft --
 * see docs/06-ui/stage-8-store-setup.md). FISCAL_CONFIGURATION_MANAGE.
 */
class InvoiceSeriesController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = InvoiceSeries::where('store_id', $actor->store_id);

        if ($request->filled('fiscal_installation_id')) {
            $query->where('fiscal_installation_id', $request->query('fiscal_installation_id'));
        }

        $paginator = $query->orderByDesc('created_at')->paginate(
            perPage: (int) $request->query('per_page', 25),
            page: (int) $request->query('page', 1),
        );

        return response()->json([
            'data' => InvoiceSeriesResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function create(CreateInvoiceSeriesRequest $request, InvoiceSeriesService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $series = $service->create($actor->store_id, $request->validated());

        return (new InvoiceSeriesResource($series))->response()->setStatusCode(201);
    }

    public function close(Request $request, InvoiceSeriesService $service, string $invoiceSeriesId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $series = $service->close($actor->store_id, $invoiceSeriesId);

        return (new InvoiceSeriesResource($series))->response();
    }
}

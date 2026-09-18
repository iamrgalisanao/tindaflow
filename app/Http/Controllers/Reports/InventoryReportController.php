<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\RendersReportResponse;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** openapi.yaml Reports tag -- Inventory On Hand/Low Stock (point-in-time, no from/to) and Inventory Movement (date-ranged), all REPORT_VIEW. */
class InventoryReportController extends Controller
{
    use RendersReportResponse;

    public function inventoryOnHand(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->inventoryOnHand($actor->store_id);

        return $this->reportResponse($request, [], $rows, $summary, [
            'product_id', 'sku', 'product_name', 'location_id', 'quantity_on_hand', 'reorder_level',
        ]);
    }

    public function lowStock(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->lowStock($actor->store_id);

        return $this->reportResponse($request, [], $rows, $summary, [
            'product_id', 'sku', 'product_name', 'quantity_on_hand', 'reorder_level', 'shortfall',
        ]);
    }

    public function inventoryMovement(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->inventoryMovement($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'occurred_at', 'product_id', 'sku', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'reason', 'created_by',
        ]);
    }
}

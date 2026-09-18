<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\RendersReportResponse;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** openapi.yaml Reports tag -- Void/Refund report, both REPORT_VIEW. Every status is shown, not just executed ones -- that visibility is the report's own point. */
class VoidRefundReportController extends Controller
{
    use RendersReportResponse;

    public function voids(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->voids($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'void_id', 'sale_id', 'invoice_number', 'requested_by', 'approved_by', 'reason',
            'terminal_id', 'fiscal_day_id', 'resolved_at', 'sale_grand_total',
        ]);
    }

    public function refunds(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->refunds($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'refund_id', 'sale_id', 'invoice_number', 'requested_by', 'approved_by', 'reason',
            'terminal_id', 'fiscal_day_id', 'refunded_at', 'refund_total',
        ]);
    }
}

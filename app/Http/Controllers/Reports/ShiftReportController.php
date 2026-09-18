<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\RendersReportResponse;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** openapi.yaml Reports tag -- Shift Report/Cash Variance Report, both REPORT_VIEW. */
class ShiftReportController extends Controller
{
    use RendersReportResponse;

    public function shifts(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->shifts($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'shift_id', 'terminal_id', 'cashier_id', 'opened_at', 'closed_at',
            'opening_cash', 'declared_cash', 'expected_cash', 'variance',
        ]);
    }

    public function cashVariance(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->cashVariance($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'shift_id', 'terminal_id', 'cashier_id', 'closed_at', 'expected_cash', 'declared_cash', 'variance',
        ]);
    }
}

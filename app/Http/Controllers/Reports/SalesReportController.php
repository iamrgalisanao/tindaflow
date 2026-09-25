<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\RendersReportResponse;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** openapi.yaml Reports tag -- the eight Sale-ledger-derived reports, all REPORT_VIEW. */
class SalesReportController extends Controller
{
    use RendersReportResponse;

    public function dailySalesSummary(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        $from = $this->fromDate($request);
        $to = $this->toDate($request);

        ['rows' => $rows, 'summary' => $summary] = $service->dailySalesSummary($actor->store_id, $from, $to);

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'business_date', 'gross_sales', 'discount_total', 'taxable_sales', 'vat_exempt_sales',
            'zero_rated_sales', 'vat_amount', 'grand_total', 'transaction_count', 'non_vat_sales',
        ]);
    }

    public function salesByDateRange(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->salesByDateRange($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'sold_at', 'invoice_number', 'transaction_number', 'terminal_id', 'cashier_id',
            'subtotal', 'discount_total', 'grand_total', 'status',
        ]);
    }

    public function salesByProduct(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        $productId = $request->query('product_id');
        ['rows' => $rows, 'summary' => $summary] = $service->salesByProduct($actor->store_id, $this->fromDate($request), $this->toDate($request), $productId);

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to'), 'product_id' => $productId], $rows, $summary, [
            'product_id', 'sku', 'product_name', 'quantity_sold', 'gross_sales', 'net_sales', 'tax_amount',
        ]);
    }

    public function salesByCategory(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        $categoryId = $request->query('category_id');
        ['rows' => $rows, 'summary' => $summary] = $service->salesByCategory($actor->store_id, $this->fromDate($request), $this->toDate($request), $categoryId);

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to'), 'category_id' => $categoryId], $rows, $summary, [
            'category_id', 'category_name', 'quantity_sold', 'gross_sales', 'net_sales',
        ]);
    }

    public function salesByCashier(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        $cashierId = $request->query('cashier_id');
        ['rows' => $rows, 'summary' => $summary] = $service->salesByCashier($actor->store_id, $this->fromDate($request), $this->toDate($request), $cashierId);

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to'), 'cashier_id' => $cashierId], $rows, $summary, [
            'cashier_id', 'cashier_name', 'transaction_count', 'gross_sales', 'void_count', 'refund_count',
        ]);
    }

    public function salesByPaymentMethod(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->salesByPaymentMethod($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'payment_method', 'transaction_count', 'total_amount',
        ]);
    }

    public function taxBreakdown(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->taxBreakdown($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'business_date', 'taxable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'vat_amount', 'non_vat_sales',
        ]);
    }

    public function discounts(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->discounts($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'sold_at', 'invoice_number', 'line_discount_total', 'order_discount_total', 'discount_total',
        ]);
    }

    public function salesByHour(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->salesByHour($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'hour', 'transaction_count', 'gross_sales', 'discount_total', 'grand_total',
        ]);
    }

    public function grossProfit(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->grossProfit($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'business_date', 'transaction_count', 'net_sales', 'cost_of_goods_sold', 'gross_profit',
            'gross_margin_percent', 'lines_with_unknown_cost',
        ]);
    }

    public function grossProfitByProduct(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->grossProfitByProduct($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'product_id', 'sku', 'product_name', 'quantity_sold', 'net_sales', 'cost_of_goods_sold',
            'gross_profit', 'gross_margin_percent', 'lines_with_unknown_cost',
        ]);
    }

    public function productVelocity(Request $request, ReportQueryService $service): Response
    {
        $actor = Auth::guard('web')->user();
        ['rows' => $rows, 'summary' => $summary] = $service->productVelocity($actor->store_id, $this->fromDate($request), $this->toDate($request));

        return $this->reportResponse($request, ['from' => $request->query('from'), 'to' => $request->query('to')], $rows, $summary, [
            'product_id', 'sku', 'product_name', 'quantity_sold', 'transaction_count', 'net_sales', 'quantity_on_hand',
        ]);
    }
}

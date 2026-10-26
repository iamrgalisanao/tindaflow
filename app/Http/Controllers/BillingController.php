<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\BillingRequest;
use App\Models\BillingRun;
use App\Models\User;
use App\Services\Billing\BillingAuditor;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingStatementCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml Billing tag (ADR-016 section 8, slice 4.1). Every operation needs BILLING_MANAGE (MANAGER and ADMIN; a cashier gets 403),
 * reads and exports included, and everything is scoped to the actor's own store. billingStatementGet is a read of the live ledger and
 * stores nothing; billingRunCreate is the explicit action that saves one as a frozen, numbered, append-only run. `Accept: text/csv` on
 * billingStatementGet and billingRunGet returns the CSV (and records BILLING_EXPORTED).
 */
class BillingController extends Controller
{
    use RespondsWithPagination;

    public function statement(BillingRequest $request, BillingService $service, BillingAuditor $auditor, BillingStatementCsv $csv): Response
    {
        $statement = $service->preview($this->actor(), $request->validated());

        if ($request->header('Accept') === 'text/csv') {
            return $this->csvResponse($csv, $auditor, $statement, null, 'billing_'.$statement['criteria']['from'].'_'.$statement['criteria']['to']);
        }

        return response()->json(['data' => $statement]);
    }

    public function runCreate(BillingRequest $request, BillingService $service): JsonResponse
    {
        $run = $service->generate($this->actor(), $request->validated());

        return response()->json(['data' => $this->runPayload($run, $service, withStatement: true)], 201);
    }

    public function runList(Request $request, BillingService $service): JsonResponse
    {
        $page = $service->list($this->actor()->store_id, $this->perPage($request), (int) $request->query('page', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (BillingRun $run) => $this->runPayload($run, $service, withStatement: false))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function runGet(Request $request, BillingService $service, BillingAuditor $auditor, BillingStatementCsv $csv, string $runId): Response
    {
        $run = $service->find($this->actor()->store_id, $runId);

        if ($request->header('Accept') === 'text/csv') {
            return $this->csvResponse($csv, $auditor, $run->statement, $run, 'billing_'.$run->run_number);
        }

        return response()->json(['data' => $this->runPayload($run, $service, withStatement: true)]);
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function csvResponse(BillingStatementCsv $csv, BillingAuditor $auditor, array $statement, ?BillingRun $run, string $name): Response
    {
        $auditor->exported($this->actor(), $statement, $run);

        return response($csv->render($statement), 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$name.'.csv"');
    }

    /** @return array<string, mixed> */
    private function runPayload(BillingRun $run, BillingService $service, bool $withStatement): array
    {
        $payload = [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'from' => $run->from_date->toDateString(),
            'to' => $run->to_date->toDateString(),
            'period_label' => $run->period_label,
            'employee_type' => $run->employee_type,
            'employee_code' => $run->employee_code,
            'as_of' => $run->as_of->toIso8601String(),
            'generated_at' => $run->generated_at->toIso8601String(),
            'generated_by' => $run->generator === null ? ['id' => $run->generated_by] : ['id' => $run->generator->id, 'name' => $run->generator->name],
            'sold_amount' => (string) $run->charges_total,
            'returned_amount' => (string) $run->reversals_total,
            'net_amount' => (string) $run->net_total,
            'employee_count' => $run->account_count,
            'sale_count' => $run->sale_count,
            'statement_sha256' => $run->statement_sha256,
        ];

        if ($withStatement) {
            $run->loadMissing('generator:id,name');
            $payload['generated_by'] = ['id' => $run->generated_by, 'name' => $run->generator?->name];
            $payload['statement_intact'] = $service->isIntact($run);
            $payload['statement'] = $run->statement;
        }

        return $payload;
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}

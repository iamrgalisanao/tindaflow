<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\BillingChequeRequest;
use App\Http\Requests\BillingChequeReturnRequest;
use App\Models\BillingCheque;
use App\Models\User;
use App\Services\Billing\BillingAuditor;
use App\Services\Billing\BillingChequeCsv;
use App\Services\Billing\BillingChequeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml Billing tag, slice 4.2 (ADR-016 section 8): cheques against what is owed. Every operation needs BILLING_MANAGE (MANAGER and
 * ADMIN; a cashier gets 403), reads and exports included, and everything is scoped to the actor's own store. A cheque is not a sale and
 * never touches a shift or the cash drawer.
 */
class BillingChequeController extends Controller
{
    use RespondsWithPagination;

    public function balance(Request $request, BillingChequeService $service, BillingChequeCsv $csv, BillingAuditor $auditor): Response
    {
        $asOf = null;
        if ($request->query('as_of') !== null && $request->query('as_of') !== '') {
            try {
                $asOf = CarbonImmutable::parse((string) $request->query('as_of'));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['as_of' => 'Enter the as-of time as a date and time.']);
            }
        }
        $balance = $service->balance($this->actor(), $asOf);

        if ($request->header('Accept') === 'text/csv') {
            $auditor->chequeReportExported($this->actor(), 'balance', ['as_of' => $balance['as_of'], 'owed_amount' => $balance['totals']['owed_amount'], 'period_count' => $balance['totals']['period_count']]);

            return $this->csvResponse($csv->renderBalance($balance), 'billing_balance');
        }

        return response()->json(['data' => $balance]);
    }

    public function index(Request $request, BillingChequeService $service, BillingChequeCsv $csv, BillingAuditor $auditor): Response
    {
        $status = $this->statusFilter($request);

        if ($request->header('Accept') === 'text/csv') {
            $cheques = $service->all($this->actor()->store_id, $status);
            $auditor->chequeReportExported($this->actor(), 'cheques', ['status' => $status, 'cheque_count' => $cheques->count()]);

            return $this->csvResponse($csv->renderCheques($cheques), 'billing_cheques');
        }

        $page = $service->list($this->actor()->store_id, $status, $this->perPage($request), (int) $request->query('page', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (BillingCheque $cheque) => $this->payload($cheque))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(BillingChequeRequest $request, BillingChequeService $service): JsonResponse
    {
        $cheque = $service->record($this->actor(), $request->validated());

        return response()->json(['data' => $this->payload($service->find($this->actor()->store_id, $cheque->id))], 201);
    }

    public function show(BillingChequeService $service, string $chequeId): JsonResponse
    {
        return response()->json(['data' => $this->payload($service->find($this->actor()->store_id, $chequeId))]);
    }

    public function markReturned(BillingChequeReturnRequest $request, BillingChequeService $service, string $chequeId): JsonResponse
    {
        $service->markReturned($this->actor(), $chequeId, $request->validated());

        return response()->json(['data' => $this->payload($service->find($this->actor()->store_id, $chequeId))]);
    }

    private function statusFilter(Request $request): ?string
    {
        $status = $request->query('status');
        if ($status === null || $status === '') {
            return null;
        }
        if (! in_array($status, [BillingCheque::RECEIVED, BillingCheque::RETURNED], true)) {
            throw ValidationException::withMessages(['status' => 'The status must be RECEIVED or RETURNED.']);
        }

        return $status;
    }

    private function csvResponse(string $body, string $name): Response
    {
        return response($body, 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$name.'_'.now()->setTimezone((string) config('app.timezone'))->format('Ymd').'.csv"');
    }

    /** @return array<string, mixed> */
    private function payload(BillingCheque $cheque): array
    {
        return [
            'id' => $cheque->id,
            'cheque_number' => $cheque->cheque_number,
            'bank' => $cheque->bank,
            'cheque_date' => $cheque->cheque_date->toDateString(),
            'amount' => (string) $cheque->amount,
            'received_on' => $cheque->received_on->toDateString(),
            'received_by' => ['id' => $cheque->received_by, 'name' => $cheque->receiver?->name],
            'status' => $cheque->status,
            'returned_on' => $cheque->returned_on?->toDateString(),
            'returned_at' => $cheque->returned_at?->toIso8601String(),
            'returned_by' => $cheque->returned_by === null ? null : ['id' => $cheque->returned_by, 'name' => $cheque->returner?->name],
            'return_reason' => $cheque->return_reason,
            'notes' => $cheque->notes,
            'created_at' => $cheque->created_at->toIso8601String(),
            'allocations' => $cheque->allocations->map(fn ($allocation) => [
                'period_start' => $allocation->period_start->toDateString(),
                'period_end' => $allocation->period_end->toDateString(),
                'amount' => (string) $allocation->amount,
            ])->all(),
        ];
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}

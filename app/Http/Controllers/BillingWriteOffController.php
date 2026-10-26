<?php

namespace App\Http\Controllers;

use App\Domain\CutOff;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\BillingWriteOffRequest;
use App\Http\Requests\BillingWriteOffReverseRequest;
use App\Models\BillingWriteOff;
use App\Models\User;
use App\Services\Billing\BillingWriteOffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Billing tag, slice 4.3 (ADR-016 section 8): writing off an unpaid amount of a cut-off period and cancelling a write-off.
 * Every operation needs BILLING_MANAGE (MANAGER and ADMIN; a cashier gets 403), reads included, scoped to the actor's own store.
 * A write-off is not money received and never touches a shift, the cash drawer or a billing statement.
 */
class BillingWriteOffController extends Controller
{
    use RespondsWithPagination;

    public function index(Request $request, BillingWriteOffService $service): JsonResponse
    {
        $page = $service->list($this->actor()->store_id, $this->perPage($request), (int) $request->query('page', 1));
        $rows = collect($page->items());
        $reversals = $service->reversalsOf($this->actor()->store_id, $rows->where('kind', BillingWriteOff::WRITE_OFF)->pluck('id')->all());

        return response()->json([
            'data' => $rows->map(fn (BillingWriteOff $row) => $this->payload($row, $reversals[$row->id] ?? null))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(BillingWriteOffRequest $request, BillingWriteOffService $service): JsonResponse
    {
        [$row, $replayed] = $service->record($this->actor(), $request->validated());

        return response()->json(['data' => $this->payloadFor($service, $row)], $replayed ? 200 : 201);
    }

    public function reverse(BillingWriteOffReverseRequest $request, BillingWriteOffService $service, string $writeOffId): JsonResponse
    {
        $reversal = $service->reverse($this->actor(), $writeOffId, $request->validated()['reason']);

        return response()->json(['data' => $this->payloadFor($service, $reversal)], 201);
    }

    private function payloadFor(BillingWriteOffService $service, BillingWriteOff $row): array
    {
        $row = $service->find($this->actor()->store_id, $row->id);

        return $this->payload($row, $row->kind === BillingWriteOff::WRITE_OFF ? ($service->reversalsOf($row->store_id, [$row->id])[$row->id] ?? null) : null);
    }

    /** @return array<string, mixed> */
    private function payload(BillingWriteOff $row, ?BillingWriteOff $reversal): array
    {
        $isReversal = $row->kind === BillingWriteOff::REVERSAL;

        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'period_start' => $row->period_start->toDateString(),
            'period_end' => $row->period_end->toDateString(),
            'period_label' => CutOff::startingOn($row->period_start->toDateString(), (string) config('app.timezone'))?->label() ?? $row->period_start->toDateString(),
            'amount' => (string) $row->amount,
            'reason' => $row->reason,
            'cheque' => $row->cheque === null ? null : ['id' => $row->cheque->id, 'cheque_number' => $row->cheque->cheque_number, 'bank' => $row->cheque->bank],
            'reverses_write_off_id' => $row->reverses_write_off_id,
            'status' => $isReversal ? null : ($reversal === null ? 'ACTIVE' : 'REVERSED'),
            'written_by' => ['id' => $row->written_by, 'name' => $row->writer?->name],
            'written_at' => $row->written_at->toIso8601String(),
            'reversal' => $reversal === null ? null : [
                'id' => $reversal->id,
                'reason' => $reversal->reason,
                'reversed_by' => ['id' => $reversal->written_by, 'name' => $reversal->writer?->name],
                'reversed_at' => $reversal->written_at->toIso8601String(),
            ],
        ];
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}

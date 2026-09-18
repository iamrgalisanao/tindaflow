<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignFiscalInstallationTerminalRequest;
use App\Http\Requests\CreateFiscalInstallationRequest;
use App\Http\Resources\FiscalInstallationResource;
use App\Http\Resources\TerminalFiscalInstallationResource;
use App\Models\FiscalInstallation;
use App\Services\StoreSetup\FiscalInstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml FiscalInstallation tag, FISCAL_CONFIGURATION_MANAGE.
 * `fiscalInstallationGet` is deliberately not implemented in this pass --
 * the list response already returns full FiscalInstallation objects
 * inline, and this project's frozen-corpus discipline treats completing
 * an already-frozen-but-gapped 404 response as a Stage 4 content change
 * requiring its own reconstruction, distinct from this pass's approved
 * forward-only new surface (assignTerminal below, genuinely new).
 */
class FiscalInstallationController extends Controller
{
    public function list(Request $request, FiscalInstallationService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $installations = FiscalInstallation::where('store_id', $actor->store_id)
            ->with(['accreditations', 'permitsToUse', 'terminals' => fn ($query) => $query->wherePivotNull('effective_to')])
            ->orderByDesc('installed_at')
            ->get();

        return response()->json(FiscalInstallationResource::collection($installations));
    }

    public function create(CreateFiscalInstallationRequest $request, FiscalInstallationService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $installation = $service->create($actor->store_id, $request->validated());

        return (new FiscalInstallationResource($installation))->response()->setStatusCode(201);
    }

    public function assignTerminal(AssignFiscalInstallationTerminalRequest $request, FiscalInstallationService $service, string $fiscalInstallationId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $effectiveFrom = $request->validated('effective_from');

        $mapping = $service->assignTerminal(
            $actor->store_id,
            $fiscalInstallationId,
            $request->validated('terminal_id'),
            $effectiveFrom === null ? null : Carbon::parse($effectiveFrom),
        );

        return (new TerminalFiscalInstallationResource($mapping))->response()->setStatusCode(201);
    }
}

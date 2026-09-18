<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateTaxRegistrationRequest;
use App\Http\Resources\TaxRegistrationResource;
use App\Models\TaxRegistration;
use App\Services\StoreSetup\TaxRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** openapi.yaml StoreSettings tag's tax-registrations paths, FISCAL_CONFIGURATION_MANAGE (create only; list is session-only). */
class TaxRegistrationController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $registrations = TaxRegistration::where('store_id', $actor->store_id)
            ->orderByDesc('effective_from')
            ->get();

        return response()->json(TaxRegistrationResource::collection($registrations));
    }

    public function create(CreateTaxRegistrationRequest $request, TaxRegistrationService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $registration = $service->create(
            $actor->store_id,
            $request->validated('registration_type'),
            $request->validated('effective_from'),
        );

        return (new TaxRegistrationResource($registration))->response()->setStatusCode(201);
    }
}

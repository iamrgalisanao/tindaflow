<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSettingsRequest;
use App\Http\Resources\TaxRegistrationResource;
use App\Services\StoreSetup\StoreSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml StoreSettings tag. storeSettingsGet needs only a session; storeSettingsUpdate needs
 * STORE_SETTINGS_MANAGE (ADMIN). Both act on the actor's own store, which is never a request field.
 */
class StoreSettingsController extends Controller
{
    public function get(StoreSettingsService $service): JsonResponse
    {
        return $this->respond($service, $service->current(Auth::guard('web')->user()->store_id));
    }

    public function update(StoreSettingsRequest $request, StoreSettingsService $service): JsonResponse
    {
        return $this->respond($service, $service->update(Auth::guard('web')->user(), $request->validated()));
    }

    /** @param  array<string, string|null>  $settings */
    private function respond(StoreSettingsService $service, array $settings): JsonResponse
    {
        $registration = $service->currentTaxRegistration(Auth::guard('web')->user()->store_id);

        return response()->json($settings + [
            // The schema calls this required; a store that has not registered one yet reports null rather than a made-up value.
            'current_tax_registration' => $registration ? new TaxRegistrationResource($registration) : null,
        ]);
    }
}

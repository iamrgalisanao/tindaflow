<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Requests\SaleFinalizeRequest;
use App\Http\Resources\SaleDetailResource;
use App\Services\Auth\PosRequestContext;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

/**
 * openapi.yaml Sales tag -- A6: wires A4's PosRequestContext into
 * CheckoutService::finalize() exactly as stage-6c-sale-finalization.md's
 * "Authentication boundary" section specifies. terminal_id/cashier_id
 * are NEVER read from the request body or route parameters -- only from
 * the already-coherence-checked PosRequestContext ComposeAuthoritativeContext
 * attaches to the request.
 */
class SaleController extends Controller
{
    public function finalize(SaleFinalizeRequest $request, CheckoutService $service): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $sale = $service->finalize(
            $context->terminal->id,
            $context->user->id,
            $idempotencyKey,
            $request->validated(),
        );

        return (new SaleDetailResource($sale))->response()->setStatusCode(201);
    }
}

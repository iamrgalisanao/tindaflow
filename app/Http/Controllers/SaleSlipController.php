<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Resources\SaleSlipDetailResource;
use App\Services\Auth\PosRequestContext;
use App\Services\Slips\SlipReprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml SaleSlips tag, the twin of InvoiceController. saleSlipGet is a session-only read scoped to the actor's store and always
 * returns the plain original. saleSlipReprint needs an enrolled terminal and an Idempotency-Key, no dedicated capability (any store
 * staff, like invoiceReprint), and returns a wrapper around the unchanged slip, because "is this a reprint, and when" describes one
 * occurrence, never the slip.
 */
class SaleSlipController extends Controller
{
    public function get(SlipReprintService $service, string $slipId): JsonResponse
    {
        $slip = $service->find($slipId, Auth::guard('web')->user()->store_id);

        return (new SaleSlipDetailResource($slip))->response();
    }

    public function reprint(Request $request, SlipReprintService $service, string $slipId): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        ['event' => $event, 'slip' => $slip] = $service->reprint($context->terminal, $context->user, $slipId, $key);

        return response()->json([
            'reprint_event_id' => $event->id,
            'slip' => SaleSlipDetailResource::reprinted($slip, $event->occurred_at),
            'is_reprint' => true,
            'reprinted_at' => $event->occurred_at->toJSON(),
            'requested_by' => $event->actor_user_id,
        ], 201);
    }
}

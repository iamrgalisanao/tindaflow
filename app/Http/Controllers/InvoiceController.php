<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Resources\InvoiceDetailResource;
use App\Services\Auth\PosRequestContext;
use App\Services\Invoicing\InvoiceReprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Invoices tag. invoiceGet is a session-only read scoped to the actor's store and always
 * returns the plain original. invoiceReprint needs an enrolled terminal and an Idempotency-Key, no
 * dedicated capability (operation-inventory.md: any store staff), and returns InvoiceReprintResult -- a
 * wrapper around the unchanged invoice, because "is this a reprint, and when" describes one occurrence,
 * never the invoice.
 */
class InvoiceController extends Controller
{
    public function get(InvoiceReprintService $service, string $invoiceId): JsonResponse
    {
        $invoice = $service->find($invoiceId, Auth::guard('web')->user()->store_id);

        return (new InvoiceDetailResource($invoice))->response();
    }

    public function reprint(Request $request, InvoiceReprintService $service, string $invoiceId): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        ['event' => $event, 'invoice' => $invoice] = $service->reprint($context->terminal, $context->user, $invoiceId, $key);

        return response()->json([
            'reprint_event_id' => $event->id,
            'invoice' => InvoiceDetailResource::reprinted($invoice, $event->occurred_at),
            'is_reprint' => true,
            'reprinted_at' => $event->occurred_at->toJSON(),
            'requested_by' => $event->actor_user_id,
        ], 201);
    }
}

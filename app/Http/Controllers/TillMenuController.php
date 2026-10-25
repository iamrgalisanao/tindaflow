<?php

namespace App\Http\Controllers;

use App\Services\Menu\TillMenuBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml menuGet (ADR-016 slice 1.3): the read-only meal menu the till renders its picker from. Any signed-in user of the
 * store may read it (a cashier needs it; it carries no cost and nothing the till does not already show); editing the menu stays
 * CATALOG_MANAGE on the admin operations. Scoped to the actor's own store.
 */
class TillMenuController extends Controller
{
    public function show(TillMenuBuilder $builder): JsonResponse
    {
        return response()->json($builder->build(Auth::guard('web')->user()->store_id, Carbon::now()));
    }
}

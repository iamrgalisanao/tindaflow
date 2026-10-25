<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\MenuPriceSetRequest;
use App\Http\Resources\MenuPriceResource;
use App\Models\User;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * openapi.yaml Menu price operations (ADR-016 slice 1.2): the price grid of a meal by dish category (and add-on), a price edit,
 * and the price history. CATALOG_MANAGE, store scoped. A price edit appends a row (MENU-004); there is no update or delete.
 */
class MenuPriceController extends Controller
{
    use RespondsWithPagination;

    public function grid(MealService $meals, MenuPriceService $prices, string $mealId): JsonResponse
    {
        return response()->json($prices->grid($meals->find($this->actor()->store_id, $mealId)));
    }

    /** 201 with the new row; 200 with the row already in force when the price is unchanged (nothing recorded). */
    public function set(MenuPriceSetRequest $request, MenuPriceService $prices, string $mealId): JsonResponse
    {
        $result = $prices->set($this->actor(), $mealId, $request->validated());

        return (new MenuPriceResource($result['price']->load(['dishCategory:id,name', 'mealOption:id,label', 'changedBy:id,name'])))
            ->response()->setStatusCode($result['changed'] ? 201 : 200);
    }

    public function history(Request $request, MealService $meals, MenuPriceService $prices, string $mealId): JsonResponse
    {
        $meal = $meals->find($this->actor()->store_id, $mealId);
        $uuid = fn (string $key) => $request->filled($key) ? (string) $request->query($key) : null;
        $category = $uuid('dish_category_id');
        $option = $uuid('meal_option_id');
        $query = $prices->historyQuery(
            $meal,
            $category !== null && Str::isUuid($category) ? $category : ($category === null ? null : '00000000-0000-0000-0000-000000000000'),
            $option !== null && Str::isUuid($option) ? $option : ($option === null ? null : '00000000-0000-0000-0000-000000000000'),
        );

        return $this->paginatedResponse($query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)), MenuPriceResource::class);
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}

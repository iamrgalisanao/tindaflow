<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\DishAlaCarteRequest;
use App\Http\Requests\DishCategoryInputRequest;
use App\Http\Requests\DishInputRequest;
use App\Http\Requests\MealInputRequest;
use App\Http\Requests\MealOptionGroupInputRequest;
use App\Http\Requests\MealOptionInputRequest;
use App\Http\Requests\MenuReorderRequest;
use App\Http\Resources\DishCategoryResource;
use App\Http\Resources\DishResource;
use App\Http\Resources\MealOptionGroupResource;
use App\Http\Resources\MealOptionResource;
use App\Http\Resources\MealResource;
use App\Models\Dish;
use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\User;
use App\Services\Menu\DishAlaCarteService;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * openapi.yaml Menu operations (ADR-016 slice 1.2): dish categories, dishes, meals, option groups and options -- the
 * configuration behind the till's meal picker. Every operation, reads included, is CATALOG_MANAGE (the till gets its own
 * read-only payload in slice 1.3). Everything is scoped to the actor's own store: a record of another store is the matching
 * *_NOT_FOUND. There is no delete anywhere (MENU-005); hiding is deactivate. The rules live in app/Services/Menu.
 */
class MenuController extends Controller
{
    use RespondsWithPagination;

    // ---------------------------------------------------------------- dish categories

    public function dishCategoryList(Request $request): JsonResponse
    {
        $query = DishCategory::where('store_id', $this->storeId())->orderBy('sort_order')->orderBy('name')->orderBy('id');
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->paginatedResponse($query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)), DishCategoryResource::class);
    }

    public function dishCategoryCreate(DishCategoryInputRequest $request, DishCategoryService $service): JsonResponse
    {
        return (new DishCategoryResource($service->create($this->actor(), $request->validated())))->response()->setStatusCode(201);
    }

    public function dishCategoryUpdate(DishCategoryInputRequest $request, DishCategoryService $service, string $dishCategoryId): JsonResponse
    {
        return (new DishCategoryResource($service->update($this->actor(), $dishCategoryId, $request->validated())))->response();
    }

    public function dishCategoryActivate(DishCategoryService $service, string $dishCategoryId): JsonResponse
    {
        return (new DishCategoryResource($service->setActive($this->actor(), $dishCategoryId, true)))->response();
    }

    public function dishCategoryDeactivate(DishCategoryService $service, string $dishCategoryId): JsonResponse
    {
        return (new DishCategoryResource($service->setActive($this->actor(), $dishCategoryId, false)))->response();
    }

    public function dishCategoryReorder(MenuReorderRequest $request, DishCategoryService $service): JsonResponse
    {
        return response()->json(['data' => DishCategoryResource::collection($service->reorder($this->actor(), $request->validated('ids')))]);
    }

    // ---------------------------------------------------------------- dishes

    public function dishList(Request $request): JsonResponse
    {
        $query = Dish::where('store_id', $this->storeId())->with('alaCarteProducts');

        if ($request->filled('dish_category_id')) {
            $categoryId = (string) $request->query('dish_category_id');
            Str::isUuid($categoryId) ? $query->where('dish_category_id', $categoryId) : $query->whereRaw('1 = 0');
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->query('search').'%');
        }
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');

        return $this->paginatedResponse($query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)), DishResource::class);
    }

    /** openapi.yaml dishAlaCarteCreate (ADR-017 slice B, client decision 2): create the ala carte product of each chosen daily dish. */
    public function dishAlaCarteCreate(DishAlaCarteRequest $request, DishAlaCarteService $service): JsonResponse
    {
        return response()->json($service->create($this->actor(), $request->validated()), 201);
    }

    public function dishCreate(DishInputRequest $request, DishService $service): JsonResponse
    {
        return (new DishResource($service->create($this->actor(), $request->validated())))->response()->setStatusCode(201);
    }

    public function dishUpdate(DishInputRequest $request, DishService $service, string $dishId): JsonResponse
    {
        return (new DishResource($service->update($this->actor(), $dishId, $request->validated())))->response();
    }

    public function dishActivate(DishService $service, string $dishId): JsonResponse
    {
        return (new DishResource($service->setActive($this->actor(), $dishId, true)))->response();
    }

    public function dishDeactivate(DishService $service, string $dishId): JsonResponse
    {
        return (new DishResource($service->setActive($this->actor(), $dishId, false)))->response();
    }

    public function dishReorder(MenuReorderRequest $request, DishService $service): JsonResponse
    {
        $categoryId = $request->validated('dish_category_id')
            ?? throw ValidationException::withMessages(['dish_category_id' => 'Name the dish category whose dishes you are ordering.']);

        return response()->json(['data' => DishResource::collection($service->reorder($this->actor(), $categoryId, $request->validated('ids')))]);
    }

    // ---------------------------------------------------------------- meals

    public function mealList(Request $request): JsonResponse
    {
        $query = Meal::where('store_id', $this->storeId());

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('menu_group_id')) {
            $groupId = (string) $request->query('menu_group_id');
            Str::isUuid($groupId) ? $query->where('menu_group_id', $groupId) : $query->whereRaw('1 = 0');
        }
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');

        return $this->paginatedResponse($query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)), MealResource::class);
    }

    public function mealGet(MealService $service, string $mealId): JsonResponse
    {
        return (new MealResource($service->find($this->storeId(), $mealId)))->response();
    }

    public function mealCreate(MealInputRequest $request, MealService $service): JsonResponse
    {
        return (new MealResource($service->create($this->actor(), $request->validated())))->response()->setStatusCode(201);
    }

    public function mealUpdate(MealInputRequest $request, MealService $service, string $mealId): JsonResponse
    {
        return (new MealResource($service->update($this->actor(), $mealId, $request->validated())))->response();
    }

    public function mealActivate(MealService $service, string $mealId): JsonResponse
    {
        return (new MealResource($service->setActive($this->actor(), $mealId, true)))->response();
    }

    public function mealDeactivate(MealService $service, string $mealId): JsonResponse
    {
        return (new MealResource($service->setActive($this->actor(), $mealId, false)))->response();
    }

    public function mealReorder(MenuReorderRequest $request, MealService $service): JsonResponse
    {
        return response()->json(['data' => MealResource::collection($service->reorder($this->actor(), $request->validated('ids')))]);
    }

    // ---------------------------------------------------------------- option groups

    public function optionGroupCreate(MealOptionGroupInputRequest $request, MealOptionGroupService $service, string $mealId): JsonResponse
    {
        return (new MealOptionGroupResource($service->create($this->actor(), $mealId, $request->validated())))->response()->setStatusCode(201);
    }

    public function optionGroupUpdate(MealOptionGroupInputRequest $request, MealOptionGroupService $service, string $groupId): JsonResponse
    {
        return (new MealOptionGroupResource($service->update($this->actor(), $groupId, $request->validated())))->response();
    }

    public function optionGroupActivate(MealOptionGroupService $service, string $groupId): JsonResponse
    {
        return (new MealOptionGroupResource($service->setActive($this->actor(), $groupId, true)))->response();
    }

    public function optionGroupDeactivate(MealOptionGroupService $service, string $groupId): JsonResponse
    {
        return (new MealOptionGroupResource($service->setActive($this->actor(), $groupId, false)))->response();
    }

    public function optionGroupReorder(MenuReorderRequest $request, MealOptionGroupService $service, string $mealId): JsonResponse
    {
        return response()->json(['data' => MealOptionGroupResource::collection($service->reorder($this->actor(), $mealId, $request->validated('ids')))]);
    }

    // ---------------------------------------------------------------- options

    public function optionCreate(MealOptionInputRequest $request, MealOptionService $service, string $groupId): JsonResponse
    {
        return (new MealOptionResource($service->create($this->actor(), $groupId, $request->validated())))->response()->setStatusCode(201);
    }

    public function optionUpdate(MealOptionInputRequest $request, MealOptionService $service, string $optionId): JsonResponse
    {
        return (new MealOptionResource($service->update($this->actor(), $optionId, $request->validated())))->response();
    }

    public function optionActivate(MealOptionService $service, string $optionId): JsonResponse
    {
        return (new MealOptionResource($service->setActive($this->actor(), $optionId, true)))->response();
    }

    public function optionDeactivate(MealOptionService $service, string $optionId): JsonResponse
    {
        return (new MealOptionResource($service->setActive($this->actor(), $optionId, false)))->response();
    }

    public function optionReorder(MenuReorderRequest $request, MealOptionService $service, string $groupId): JsonResponse
    {
        return response()->json(['data' => MealOptionResource::collection($service->reorder($this->actor(), $groupId, $request->validated('ids')))]);
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }

    private function storeId(): string
    {
        return $this->actor()->store_id;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\MenuGroupInputRequest;
use App\Http\Requests\MenuGroupReorderRequest;
use App\Http\Resources\MenuGroupResource;
use App\Models\MenuGroup;
use App\Services\Catalog\MenuGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml MenuGroup operations (ADR-016 section 1). menuGroupList is session-only like the other catalog reads (the
 * till builds its tabs from it); create, update, activate, deactivate and reorder are CATALOG_MANAGE. Everything is scoped
 * to the actor's own store: a group of another store is MENU_GROUP_NOT_FOUND. There is no delete (MENU-005).
 */
class MenuGroupController extends Controller
{
    use RespondsWithPagination;

    /**
     * Ordered the way the tree is shown: top groups by sort order, each followed by its sub-groups. `?active=true` is
     * what the till asks for: only groups that are active AND whose parent (if any) is active, so hiding a top group hides
     * the sub-groups under it.
     */
    public function list(Request $request): JsonResponse
    {
        $storeId = Auth::guard('web')->user()->store_id;

        $query = MenuGroup::query()
            ->from('menu_groups as g')
            ->leftJoin('menu_groups as p', 'p.id', '=', 'g.parent_id')
            ->where('g.store_id', $storeId)
            ->select('g.*')
            ->orderByRaw('coalesce(p.sort_order, g.sort_order)')
            ->orderByRaw('coalesce(p.name, g.name)')
            ->orderByRaw('coalesce(p.id, g.id)')
            ->orderByRaw('(g.parent_id is not null)')
            ->orderBy('g.sort_order')->orderBy('g.name')->orderBy('g.id');

        if ($request->filled('active')) {
            $active = filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('g.active', $active);
            if ($active) {
                $query->where(fn ($q) => $q->whereNull('g.parent_id')->orWhere('p.active', true));
            }
        }

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            MenuGroupResource::class,
        );
    }

    public function create(MenuGroupInputRequest $request, MenuGroupService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new MenuGroupResource($service->create($actor, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(MenuGroupInputRequest $request, MenuGroupService $service, string $menuGroupId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new MenuGroupResource($service->update($actor, $menuGroupId, $request->validated())))->response();
    }

    public function activate(MenuGroupService $service, string $menuGroupId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new MenuGroupResource($service->setActive($actor, $menuGroupId, true)))->response();
    }

    public function deactivate(MenuGroupService $service, string $menuGroupId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new MenuGroupResource($service->setActive($actor, $menuGroupId, false)))->response();
    }

    public function reorder(MenuGroupReorderRequest $request, MenuGroupService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $groups = $service->reorder($actor, $request->validated('parent_id'), $request->validated('ids'));

        return response()->json(['data' => MenuGroupResource::collection($groups)]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\UserInputRequest;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Users tag: userList/Create/Get/Update/Deactivate, plus userActivate (forward-committed
 * 2026-09-19 -- the contract had no way back from a deactivation). All USER_MANAGE (ADMIN only),
 * session-only, scoped to the actor's own store; a user in another store is indistinguishable from
 * one that does not exist. The response is UserSummary, which never carries the password hash.
 */
class UserController extends Controller
{
    use RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = User::where('store_id', $actor->store_id);

        $role = $request->query('role');
        if (in_array($role, ['ADMIN', 'MANAGER', 'CASHIER'], true)) {
            $query->where('role', $role);
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->paginatedResponse(
            $query->orderBy('name')->orderBy('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            UserSummaryResource::class,
        );
    }

    public function get(UserService $service, string $userId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new UserSummaryResource($service->find($actor->store_id, $userId)))->response();
    }

    public function create(UserInputRequest $request, UserService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new UserSummaryResource($service->create($actor->store_id, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UserInputRequest $request, UserService $service, string $userId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new UserSummaryResource($service->update($actor->store_id, $userId, $request->validated())))->response();
    }

    public function deactivate(UserService $service, string $userId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new UserSummaryResource($service->setActive($actor->store_id, $userId, false)))->response();
    }

    public function activate(UserService $service, string $userId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        return (new UserSummaryResource($service->setActive($actor->store_id, $userId, true)))->response();
    }
}

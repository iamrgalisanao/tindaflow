<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\EmployeeInactiveException;
use App\Enums\EmployeeType;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\CreditLimitRequest;
use App\Http\Requests\EmployeeInputRequest;
use App\Http\Resources\CreditPolicyResource;
use App\Http\Resources\EmployeeCreditLookupResource;
use App\Http\Resources\EmployeeLookupResource;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\Credit\CreditLedger;
use App\Services\Employees\CreditPolicyService;
use App\Services\Employees\EmployeeImportService;
use App\Services\Employees\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * openapi.yaml Employee operations (ADR-016 section 5, slice 3.1). Everything except employeeLookup needs EMPLOYEE_MANAGE
 * (ADMIN and MANAGER, amendment 2026-10-26), reads included: a cashier cannot list, create, edit or print employees. The CSV import and
 * the credit-limit change need EMPLOYEE_POLICY_MANAGE (ADMIN only). employeeLookup is the one
 * till operation, open to any signed-in user, and it returns one employee by exact code or nothing. Everything is scoped to the
 * actor's own store. There is no delete: deactivating is the way out, and the code is never reissued.
 */
class EmployeeController extends Controller
{
    use RespondsWithPagination;

    /** Capped so the print sheet is one request; the screen asks for a filtered set. */
    private const ID_CARD_LIMIT = 500;

    public function list(Request $request, EmployeeService $service): JsonResponse
    {
        $query = $service->query($this->actor()->store_id, $this->filters($request));

        return $this->paginatedResponse($query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)), EmployeeResource::class);
    }

    public function create(EmployeeInputRequest $request, EmployeeService $service): JsonResponse
    {
        return (new EmployeeResource($service->create($this->actor(), $request->validated())))->response()->setStatusCode(201);
    }

    public function get(EmployeeService $service, string $employeeId): JsonResponse
    {
        return (new EmployeeResource($service->find($this->actor()->store_id, $employeeId)))->response();
    }

    public function update(EmployeeInputRequest $request, EmployeeService $service, string $employeeId): JsonResponse
    {
        return (new EmployeeResource($service->update($this->actor(), $employeeId, $request->validated())))->response();
    }

    public function activate(EmployeeService $service, string $employeeId): JsonResponse
    {
        return (new EmployeeResource($service->setActive($this->actor(), $employeeId, true)))->response();
    }

    public function deactivate(EmployeeService $service, string $employeeId): JsonResponse
    {
        return (new EmployeeResource($service->setActive($this->actor(), $employeeId, false)))->response();
    }

    /** The request body is the CSV itself; answers 202 with the result. `?dry_run=true` reports and changes nothing. */
    public function import(Request $request, EmployeeImportService $service): JsonResponse
    {
        return response()->json($service->import($this->actor(), $request->getContent(), $request->boolean('dry_run')), 202);
    }

    /**
     * The data for the printable ID cards: the filtered set (or the listed `ids`), active employees by default, unpaged, at most
     * ID_CARD_LIMIT. Each card is the name, the type and the code (the code is also the QR value).
     */
    public function idCards(Request $request, EmployeeService $service): JsonResponse
    {
        $filters = $this->filters($request);
        $filters['active'] ??= true;
        $query = $service->query($this->actor()->store_id, $filters);

        if ($request->has('ids')) {
            $ids = array_values(array_filter((array) $request->query('ids'), fn ($id) => is_string($id) && Str::isUuid($id)));
            $query->whereIn('id', $ids);
        }

        $employees = $query->limit(self::ID_CARD_LIMIT + 1)->get();
        if ($employees->count() > self::ID_CARD_LIMIT) {
            throw ValidationException::withMessages(['search' => 'More than '.self::ID_CARD_LIMIT.' employees match. Narrow the search or print by type.']);
        }

        return response()->json(['data' => EmployeeLookupResource::collection($employees)]);
    }

    /** Any signed-in till user. `code` is what the scanner typed or the cashier keyed in. */
    public function lookup(Request $request, EmployeeService $service, CreditLedger $ledger): JsonResponse
    {
        $code = $request->query('code');
        if (! is_string($code) || trim($code) === '' || strlen($code) > 64) {
            throw ValidationException::withMessages(['code' => 'Scan or type the employee code.']);
        }

        $employee = $service->lookup($this->actor()->store_id, $code);

        return (new EmployeeCreditLookupResource($employee, $ledger->status($employee, now())))->response();
    }

    /**
     * ADR-016 slice 3.2: the limit, what is used and what remains for the current cut-off, so the till can refresh it before it charges.
     * Advisory: checkout re-checks under the employee lock. Any signed-in user, store-scoped; an inactive employee is EMPLOYEE_INACTIVE.
     */
    public function creditStatus(EmployeeService $service, CreditLedger $ledger, string $employeeId): JsonResponse
    {
        $employee = $service->find($this->actor()->store_id, $employeeId);
        if (! $employee->active) {
            throw EmployeeInactiveException::forCode($employee->employee_code);
        }

        return (new EmployeeCreditLookupResource($employee, $ledger->status($employee, now())))->response();
    }

    public function creditPolicies(CreditPolicyService $service): JsonResponse
    {
        return response()->json(['data' => CreditPolicyResource::collection($service->all($this->actor()->store_id))]);
    }

    public function creditPolicySetLimit(CreditLimitRequest $request, CreditPolicyService $service, string $employeeType): JsonResponse
    {
        $type = EmployeeType::tryFrom($employeeType);
        if ($type === null) {
            throw ValidationException::withMessages(['employee_type' => 'The type must be DIRECT or SUBCONTRACTOR.']);
        }

        return (new CreditPolicyResource($service->setLimit($this->actor(), $type, $request->validated('limit_per_cutoff'))))->response();
    }

    /** @return array{search: string|null, employee_type: string|null, active: bool|null} */
    private function filters(Request $request): array
    {
        $type = $request->query('employee_type');
        if ($type !== null && $type !== '' && EmployeeType::tryFrom((string) $type) === null) {
            throw ValidationException::withMessages(['employee_type' => 'The type must be DIRECT or SUBCONTRACTOR.']);
        }

        return [
            'search' => is_string($request->query('search')) ? $request->query('search') : null,
            'employee_type' => $type === '' ? null : $type,
            'active' => $request->filled('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN) : null,
        ];
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}

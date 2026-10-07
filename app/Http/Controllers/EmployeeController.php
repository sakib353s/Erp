<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Hr\Models\Department;
use App\Domain\Hr\Models\Designation;
use App\Domain\People\Employee;
use App\Http\Requests\StoreEmployeeRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Employee administration (traceability 10-01…10-03). Branch-scoped
 * listing, server-side branch guard on write, optional user link with
 * role assignment kept separate from employee type (10-02).
 */
class EmployeeController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $ids = $actor->accessibleBranchIds();

        $query = Employee::query()->with('branch')->orderBy('code');

        if ($ids !== null) {
            $query->whereIn('branch_id', $ids);
        }

        if ($status = (string) $request->query('status')) {
            $query->where('status', $status);
        }

        if ($employment = (string) $request->query('employment_status')) {
            $query->where('employment_status', $employment);
        }

        if ($departmentId = (int) $request->query('department')) {
            $query->where('department_id', $departmentId);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return view('employees.index', [
            'employees' => $query->with(['departmentRecord:id,name', 'designationRecord:id,name'])->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $status,
            'employmentStatus' => $employment,
            'departmentId' => $departmentId,
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('employees.form', [
            'employee' => new Employee([
                'status' => 'active',
                'employment_status' => 'active',
                'is_technician' => false,
                'branch_id' => $request->user()->default_branch_id,
            ]),
            'branches' => $this->branches($request),
            'managers' => Employee::query()->orderBy('full_name')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->active()->orderBy('name')->get(['id', 'name', 'department_id']),
            'mode' => 'create',
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $fullName = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        $data['full_name'] = $data['full_name'] ?? ($fullName !== '' ? $fullName : $data['first_name']);
        $data['company_id'] = $request->user()->company_id;

        /** @var Employee $employee */
        $employee = Employee::create($data);

        $this->audit->record([
            'action' => 'record.create',
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'actor_id' => $request->user()->id,
            'after' => ['code' => $employee->code, 'full_name' => $employee->full_name],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('employees.show', $employee)->with('status', 'Employee created.');
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->assertBranchAllowed($request, (int) $employee->branch_id);

        return view('employees.show', [
            'employee' => $employee->load(['branch', 'user', 'manager']),
            'branches' => $this->branches($request),
            'managers' => Employee::query()->whereKeyNot($employee->id)->orderBy('full_name')->get(),
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, Employee $employee): View
    {
        $this->assertBranchAllowed($request, (int) $employee->branch_id);

        return view('employees.form', [
            'employee' => $employee,
            'branches' => $this->branches($request),
            'managers' => Employee::query()->whereKeyNot($employee->id)->orderBy('full_name')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->active()->orderBy('name')->get(['id', 'name', 'department_id']),
            'mode' => 'edit',
        ]);
    }

    public function update(StoreEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $employee->branch_id);
        $this->assertBranchAllowed($request, (int) $request->input('branch_id'));

        $data = $request->validated();

        if (! isset($data['full_name']) || $data['full_name'] === null || $data['full_name'] === '') {
            $fullName = trim(($data['first_name'] ?? $employee->first_name).' '.($data['last_name'] ?? $employee->last_name));
            $data['full_name'] = $fullName !== '' ? $fullName : ($data['first_name'] ?? $employee->first_name);
        }

        unset($data['company_id']);

        $employee->fill($data);
        $employee->save();

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'actor_id' => $request->user()->id,
            'before' => ['full_name' => $employee->getOriginal('full_name'), 'status' => $employee->getOriginal('status')],
            'after' => ['full_name' => $employee->full_name, 'status' => $employee->status],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('employees.show', $employee)->with('status', 'Employee updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $employee->branch_id);

        $snapshot = ['code' => $employee->code, 'full_name' => $employee->full_name];
        $employee->delete();

        $this->audit->record([
            'action' => 'record.delete',
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'actor_id' => $request->user()->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('employees.index')->with('status', 'Employee deleted.');
    }

    /** @return Collection<int, Branch> */
    protected function branches(Request $request)
    {
        $ids = $request->user()->accessibleBranchIds();
        $query = Branch::query()->where('is_active', true)->orderBy('name');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    protected function assertBranchAllowed(Request $request, int $branchId): void
    {
        $request->user()->hasBranchAccess($branchId)
            || abort(403, 'That branch is outside your own access.');
    }
}

<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\People\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FlagEmployeeAsSalesPerson (02-78). Marks an employee as sales-person
 * on the roster (is_salesperson). Employee master remains the source of truth.
 */
class FlagEmployeeAsSalesPerson
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Employee $employee, bool $isSalesperson, Request $request): Employee
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $employee->company_id !== (int) $companyId) {
            throw new RuntimeException('Employee not found for this company.');
        }

        return DB::transaction(function () use ($employee, $isSalesperson, $request) {
            $fresh = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $before = ['is_salesperson' => (bool) $fresh->is_salesperson];
            $fresh->is_salesperson = $isSalesperson;
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.salesperson_flagged',
                'entity_type' => 'employee',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'before' => $before,
                'after' => [
                    'is_salesperson' => $isSalesperson,
                    'code' => $fresh->code,
                    'full_name' => $fresh->full_name,
                ],
            ]);

            return $fresh;
        });
    }
}

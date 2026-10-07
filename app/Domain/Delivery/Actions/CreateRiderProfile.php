<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\People\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateRiderProfile / update (02-93): put an employee on the own-rider
 * roster. One profile per employee per company; the employee master
 * remains the source of truth for who the person is.
 */
class CreateRiderProfile
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{vehicle_type?: ?string, vehicle_plate?: ?string, is_available?: bool, is_active?: bool, gps_consent?: bool}  $payload
     */
    public function handle(Employee $employee, array $payload, Request $request): RiderProfile
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $employee->company_id !== $companyId) {
            throw new RuntimeException('Employee not found for this company.');
        }

        return DB::transaction(function () use ($employee, $payload, $companyId, $request) {
            $profile = RiderProfile::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            $creating = $profile === null;

            $before = $creating ? null : [
                'vehicle_type' => $profile->vehicle_type,
                'vehicle_plate' => $profile->vehicle_plate,
                'is_available' => $profile->is_available,
                'is_active' => $profile->is_active,
                'gps_consent' => $profile->gps_consent,
            ];

            $profile ??= new RiderProfile([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
            ]);

            $profile->fill([
                'vehicle_type' => $payload['vehicle_type'] ?? $profile->vehicle_type,
                'vehicle_plate' => $payload['vehicle_plate'] ?? $profile->vehicle_plate,
                'is_available' => $payload['is_available'] ?? $profile->is_available ?? true,
                'is_active' => $payload['is_active'] ?? $profile->is_active ?? true,
                'gps_consent' => $payload['gps_consent'] ?? $profile->gps_consent ?? false,
            ]);
            $profile->save();

            $this->audit->record([
                'action' => $creating ? 'sales.rider_profile_created' : 'sales.rider_profile_updated',
                'entity_type' => 'rider_profile',
                'entity_id' => $profile->id,
                'actor_id' => $request->user()->id,
                'before' => $before,
                'after' => [
                    'employee_id' => $employee->id,
                    'code' => $employee->code,
                    'full_name' => $employee->full_name,
                    'vehicle_type' => $profile->vehicle_type,
                    'vehicle_plate' => $profile->vehicle_plate,
                    'is_available' => $profile->is_available,
                    'is_active' => $profile->is_active,
                    'gps_consent' => $profile->gps_consent,
                ],
            ]);

            return $profile;
        });
    }
}

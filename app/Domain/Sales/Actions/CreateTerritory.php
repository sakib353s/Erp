<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\DeliveryZone;
use App\Domain\People\Employee;
use App\Domain\Sales\Territory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateTerritory (02-87). Sales territory with optional delivery zone
 * link and rep assignment. Config only — no GL/stock.
 */
class CreateTerritory
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   code: string,
     *   name: string,
     *   description?: string|null,
     *   delivery_zone_id?: int|null,
     *   is_active?: bool,
     *   employee_ids?: array<int, int>,
     * } $payload
     */
    public function handle(array $payload, Request $request): Territory
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        if ($code === '') {
            throw new RuntimeException('Territory code is required.');
        }

        $zoneId = $payload['delivery_zone_id'] ?? null;
        if ($zoneId !== null) {
            $zone = DeliveryZone::query()
                ->where('company_id', $companyId)
                ->find((int) $zoneId);
            if ($zone === null) {
                throw new RuntimeException('Delivery zone not found for this company.');
            }
        }

        $employeeIds = array_values(array_unique(array_map(
            'intval',
            (array) ($payload['employee_ids'] ?? []),
        )));
        if ($employeeIds !== []) {
            $found = Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (count($found) !== count($employeeIds)) {
                throw new RuntimeException('One or more employees not found for this company.');
            }
            $employeeIds = $found;
        }

        return DB::transaction(function () use ($payload, $companyId, $code, $zoneId, $employeeIds, $request) {
            $existing = Territory::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw new RuntimeException("Territory code [{$code}] already exists.");
            }

            $territory = Territory::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'code' => $code,
                'name' => (string) $payload['name'],
                'description' => $payload['description'] ?? null,
                'delivery_zone_id' => $zoneId,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'created_by' => $request->user()->id,
            ]);

            if ($employeeIds !== []) {
                $territory->employees()->sync($employeeIds);
            }

            $this->audit->record([
                'action' => 'sales.territory_created',
                'entity_type' => 'territory',
                'entity_id' => $territory->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'code' => $territory->code,
                    'name' => $territory->name,
                    'delivery_zone_id' => $zoneId,
                    'employee_ids' => $employeeIds,
                ],
            ]);

            return $territory->load('employees');
        });
    }
}

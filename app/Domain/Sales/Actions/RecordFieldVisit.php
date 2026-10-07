<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Customer;
use App\Domain\People\Employee;
use App\Domain\Sales\FieldVisit;
use App\Domain\Sales\GpsPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RecordFieldVisit (02-85/02-86). Create or transition a field visit.
 * GPS coordinates / gps_points rows are stored ONLY when gps_consent is
 * true — privacy gate from row 02-86. DOC only — no GL/stock.
 */
class RecordFieldVisit
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   employee_id?: int,
     *   field_visit_id?: int|null,
     *   customer_id?: int|null,
     *   visit_date?: string|null,
     *   purpose?: string|null,
     *   location_note?: string|null,
     *   notes?: string|null,
     *   gps_consent?: bool,
     *   latitude?: float|null,
     *   longitude?: float|null,
     *   action?: string,
     *   status?: string,
     * } $payload
     */
    public function handle(array $payload, Request $request): FieldVisit
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $action = (string) ($payload['action'] ?? 'create');

        return DB::transaction(function () use ($payload, $companyId, $action, $request) {
            if ($action === 'create') {
                return $this->createVisit($payload, $companyId, $request);
            }

            $visitId = (int) ($payload['field_visit_id'] ?? 0);
            $visit = FieldVisit::query()
                ->where('company_id', $companyId)
                ->whereKey($visitId)
                ->lockForUpdate()
                ->first();
            if ($visit === null) {
                throw new RuntimeException('Field visit not found.');
            }

            if ($action === 'start') {
                if ($visit->status !== 'planned') {
                    throw new RuntimeException("Visit status [{$visit->status}] cannot be started.");
                }
                $visit->status = 'in_progress';
                $visit->started_at = now();
                if (($payload['gps_consent'] ?? null) !== null) {
                    $visit->gps_consent = (bool) $payload['gps_consent'];
                }
                $this->applyGps($visit, $payload);
                $visit->save();
            } elseif ($action === 'complete') {
                if (! in_array($visit->status, ['planned', 'in_progress'], true)) {
                    throw new RuntimeException("Visit status [{$visit->status}] cannot be completed.");
                }
                $visit->status = 'completed';
                $visit->ended_at = now();
                if ($visit->started_at === null) {
                    $visit->started_at = now();
                }
                $this->applyGps($visit, $payload);
                $visit->save();
            } elseif ($action === 'cancel') {
                if (in_array($visit->status, ['completed', 'cancelled'], true)) {
                    throw new RuntimeException("Visit status [{$visit->status}] cannot be cancelled.");
                }
                $visit->status = 'cancelled';
                $visit->ended_at = now();
                $visit->save();
            } else {
                throw new RuntimeException("Unknown field visit action [{$action}].");
            }

            $this->audit->record([
                'action' => 'sales.field_visit_'.$action,
                'entity_type' => 'field_visit',
                'entity_id' => $visit->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'status' => $visit->status,
                    'gps_consent' => (bool) $visit->gps_consent,
                ],
            ]);

            return $visit;
        });
    }

    /** @param array<string, mixed> $payload */
    protected function createVisit(array $payload, int $companyId, Request $request): FieldVisit
    {
        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);
        if ($employee === null) {
            throw new RuntimeException('Employee not found for this company.');
        }

        $customerId = $payload['customer_id'] ?? null;
        if ($customerId !== null) {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->find((int) $customerId);
            if ($customer === null) {
                throw new RuntimeException('Customer not found for this company.');
            }
        }

        $consent = (bool) ($payload['gps_consent'] ?? false);
        $visit = FieldVisit::create([
            'company_id' => $companyId,
            'branch_id' => $request->user()->default_branch_id,
            'employee_id' => $employee->id,
            'customer_id' => $customerId,
            'visit_date' => $payload['visit_date'] ?? now()->toDateString(),
            'started_at' => null,
            'ended_at' => null,
            'status' => 'planned',
            'purpose' => $payload['purpose'] ?? null,
            'location_note' => $payload['location_note'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'gps_consent' => $consent,
            'latitude' => null,
            'longitude' => null,
            'created_by' => $request->user()->id,
        ]);

        $this->applyGps($visit, $payload);
        $visit->save();

        $this->audit->record([
            'action' => 'sales.field_visit_created',
            'entity_type' => 'field_visit',
            'entity_id' => $visit->id,
            'actor_id' => $request->user()->id,
            'after' => [
                'employee_id' => $employee->id,
                'customer_id' => $customerId,
                'visit_date' => $visit->visit_date?->toDateString(),
                'gps_consent' => $consent,
            ],
        ]);

        return $visit;
    }

    /**
     * Privacy gate: never persist coordinates or gps_points without consent.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyGps(FieldVisit $visit, array $payload): void
    {
        if (! $visit->gps_consent) {
            $visit->latitude = null;
            $visit->longitude = null;

            return;
        }

        $lat = $payload['latitude'] ?? null;
        $lng = $payload['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new RuntimeException('GPS coordinates are out of range.');
        }

        $visit->latitude = number_format($lat, 7, '.', '');
        $visit->longitude = number_format($lng, 7, '.', '');

        GpsPoint::create([
            'company_id' => $visit->company_id,
            'employee_id' => $visit->employee_id,
            'field_visit_id' => $visit->id,
            'latitude' => $visit->latitude,
            'longitude' => $visit->longitude,
            'captured_at' => now(),
        ]);
    }
}

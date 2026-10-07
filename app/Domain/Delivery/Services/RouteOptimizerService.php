<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Sales\GpsPoint;

/**
 * 02-94 Local, deterministic route plan: order each rider's open
 * stops by delivery zone (A→Z), then district sort order, then
 * shipment id. No external routing/AI service is called, and no
 * distance is fabricated — customer stops carry no coordinates, so
 * the plan says so instead of inventing km figures. Rider positions
 * come only from consent-gated gps_points (source=rider).
 */
class RouteOptimizerService
{
    /** Shipment states that are no longer routeable stops. */
    public const CLOSED_STATUSES = [
        Shipment::STATUS_DELIVERED,
        Shipment::STATUS_DELIVERY_FAILED,
        Shipment::STATUS_CANCELLED,
    ];

    public const METHOD_NOTE = 'Stops are ordered locally and deterministically — delivery zone (A to Z), then district sort order, then shipment id. No external routing or AI service is called.';

    public const DISTANCE_NOTE = 'Customer stops carry no coordinates, so distances are not computed — no distance figures are shown rather than estimated ones.';

    /** @return array{method_note: string, distance_note: string, generated_at: string, groups: array<int, array<string, mixed>>, totals: array<string, int>} */
    public function plan(int $companyId): array
    {
        $assignments = RiderAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                RiderAssignment::STATUS_PENDING,
                RiderAssignment::STATUS_ACCEPTED,
            ])
            ->with([
                'shipment:id,company_id,sales_order_id,status',
                'shipment.order:id,order_no,customer_id',
                'shipment.order.customer:id,code,name,district_id',
                'rider',
            ])
            ->orderBy('id')
            ->get();

        $zoneByDistrict = $this->zoneByDistrict($companyId);

        $groups = [];
        $closedExcluded = 0;
        $routedShipmentIds = [];

        foreach ($assignments as $assignment) {
            $shipment = $assignment->shipment;

            if ($shipment === null) {
                continue;
            }

            if (in_array($shipment->status, self::CLOSED_STATUSES, true)) {
                $closedExcluded++;

                continue;
            }

            $routedShipmentIds[] = (int) $shipment->id;

            $customer = $shipment->order?->customer;
            $district = $customer?->district;
            $zoneName = $district !== null ? ($zoneByDistrict[(int) $district->id] ?? null) : null;

            $stop = [
                'shipment_id' => (int) $shipment->id,
                'order_no' => (string) ($shipment->order?->order_no ?? '#'.$shipment->id),
                'customer_name' => (string) ($customer?->name ?? 'Unknown customer'),
                'district_name' => $district?->name,
                'zone_name' => $zoneName,
                'shipment_status' => $shipment->status,
                'assignment_status' => $assignment->status,
                'reason' => $this->reason($zoneName, $district, $shipment),
                'sort' => [
                    $zoneName === null ? 1 : 0,
                    $zoneName === null ? '' : mb_strtolower($zoneName),
                    $district?->sort ?? PHP_INT_MAX,
                    mb_strtolower($district?->name ?? ''),
                    (int) $shipment->id,
                ],
            ];

            $key = $assignment->rider_employee_id !== null
                ? 'emp:'.$assignment->rider_employee_id
                : 'name:'.mb_strtolower((string) $assignment->rider_name);

            $groups[$key]['stops'][] = $stop;
            $groups[$key]['rider_label'] ??= (string) ($assignment->rider?->full_name
                ?? $assignment->rider_name
                ?? 'Rider');
        }

        $unassignedOpen = Shipment::query()
            ->where('company_id', $companyId)
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->whereNotIn('id', array_unique($routedShipmentIds))
            ->count();

        $plan = [];

        foreach ($groups as $key => $group) {
            usort($group['stops'], fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

            foreach ($group['stops'] as $index => $stop) {
                unset($stop['sort']);
                $stop['seq'] = $index + 1;
                $group['stops'][$index] = $stop;
            }

            $plan[] = [
                'key' => $key,
                'rider_label' => $group['rider_label'],
                'position' => $this->riderPosition($companyId, $key),
                'stops' => $group['stops'],
                'stop_count' => count($group['stops']),
            ];
        }

        usort($plan, fn (array $a, array $b) => [
            mb_strtolower($a['rider_label']),
            $a['key'],
        ] <=> [
            mb_strtolower($b['rider_label']),
            $b['key'],
        ]);

        return [
            'method_note' => self::METHOD_NOTE,
            'distance_note' => self::DISTANCE_NOTE,
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'groups' => $plan,
            'totals' => [
                'stops' => array_sum(array_column($plan, 'stop_count')),
                'riders' => count($plan),
                'unassigned_open_shipments' => (int) $unassignedOpen,
                'closed_shipments_excluded' => $closedExcluded,
            ],
        ];
    }

    /** district_id => zone name (first active zone by name wins — deterministic). */
    private function zoneByDistrict(int $companyId): array
    {
        $map = [];

        $zones = DeliveryZone::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('districts:id,name,sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($zones as $zone) {
            foreach ($zone->districts as $district) {
                $map[(int) $district->id] ??= (string) $zone->name;
            }
        }

        return $map;
    }

    private function reason(?string $zoneName, mixed $district, Shipment $shipment): string
    {
        $orderNo = (string) ($shipment->order?->order_no ?? '#'.$shipment->id);

        if ($zoneName !== null) {
            return sprintf('Zone "%s" group · district sort %d · %s', $zoneName, (int) $district->sort, $orderNo);
        }

        if ($district === null) {
            return 'No district on customer — no zone applies · '.$orderNo;
        }

        return sprintf('No delivery zone — ordered after zoned stops by district sort %d · %s', (int) $district->sort, $orderNo);
    }

    /** @return array{state: string, text: string} */
    private function riderPosition(int $companyId, string $key): array
    {
        if (! str_starts_with($key, 'emp:')) {
            return [
                'state' => 'not_on_roster',
                'text' => 'Not on the rider roster (name-only assignment) — no position available.',
            ];
        }

        $employeeId = (int) substr($key, 4);

        $profile = RiderProfile::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->first();

        if ($profile === null) {
            return [
                'state' => 'not_on_roster',
                'text' => 'No rider profile for this employee — no position available.',
            ];
        }

        if (! $profile->gps_consent) {
            return [
                'state' => 'sharing_off',
                'text' => 'GPS sharing not approved — position unknown.',
            ];
        }

        $point = GpsPoint::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('source', 'rider')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if ($point === null) {
            return [
                'state' => 'no_position',
                'text' => 'Sharing approved — no position recorded yet.',
            ];
        }

        return [
            'state' => 'known',
            'text' => 'Last shared position '.$point->captured_at->format('Y-m-d H:i').'.',
        ];
    }
}

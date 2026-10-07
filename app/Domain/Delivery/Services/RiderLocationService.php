<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\GpsPoint;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * RiderLocationService (02-93): rider position intake under approved
 * sharing only. A coordinate is persisted as a gps_points row
 * (source=rider) ONLY while the rider's profile has gps_consent —
 * without it the write is refused with an explicit reason, never
 * silently stored. Field-visit points (source=field_visit) are a
 * separate, already consent-gated path (RecordFieldVisit).
 */
class RiderLocationService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, captured_at?: string|null}  $payload
     */
    public function record(RiderProfile $profile, array $payload, Request $request): GpsPoint
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $profile->company_id !== $companyId) {
            throw new RuntimeException('Rider not found for this company.');
        }

        if (! $profile->gps_consent) {
            throw new RuntimeException(
                'GPS sharing is not approved for this rider — enable rider consent before recording locations.',
            );
        }

        $lat = (float) $payload['latitude'];
        $lng = (float) $payload['longitude'];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new RuntimeException('GPS coordinates are out of range.');
        }

        $capturedAt = $payload['captured_at'] ?? null;

        $point = GpsPoint::query()->create([
            'company_id' => $companyId,
            'employee_id' => $profile->employee_id,
            'field_visit_id' => null,
            'source' => 'rider',
            'latitude' => number_format($lat, 7, '.', ''),
            'longitude' => number_format($lng, 7, '.', ''),
            'captured_at' => $capturedAt !== null ? date(DATE_ATOM, strtotime((string) $capturedAt)) : now(),
        ]);

        // Audit that a consented position was recorded — never the
        // coordinates themselves (location privacy).
        $this->audit->record([
            'action' => 'sales.rider_location_recorded',
            'entity_type' => 'rider_profile',
            'entity_id' => $profile->id,
            'actor_id' => $request->user()->id,
            'after' => [
                'employee_id' => $profile->employee_id,
                'gps_point_id' => $point->id,
                'gps_consent' => true,
            ],
        ]);

        return $point;
    }
}

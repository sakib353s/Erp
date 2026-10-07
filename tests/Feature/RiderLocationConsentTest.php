<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Services\RiderLocationService;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use App\Domain\Sales\GpsPoint;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-93c RiderLocationService + GPS screen: a coordinate is stored
 * ONLY while the rider's profile has gps_consent (approved sharing) —
 * without it the write is refused with an explicit reason and zero
 * rows; coordinates are never written into the audit payload; the
 * GPS screen states the truth per rider (consent, position or honest
 * absence).
 */
class RiderLocationConsentTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__rider-gps', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeRider(bool $consent, ?int $userId = null): RiderProfile
    {
        $employee = Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'GPS-'.Employee::query()->count(),
            'first_name' => 'Gps',
            'full_name' => 'Gps Rider',
            'employment_status' => 'active',
            'status' => 'active',
            'user_id' => $userId,
        ]);

        return app(CreateRiderProfile::class)->handle($employee, [
            'gps_consent' => $consent,
        ], $this->httpRequest());
    }

    public function test_location_without_consent_is_refused_with_zero_rows(): void
    {
        $profile = $this->makeRider(false);

        try {
            app(RiderLocationService::class)->record($profile, [
                'latitude' => 23.7805,
                'longitude' => 90.4075,
            ], $this->httpRequest());
            $this->fail('Recording without consent must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('consent', strtolower($e->getMessage()));
        }

        $this->assertSame(0, GpsPoint::query()->count());
        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'sales.rider_location_recorded')
            ->count());
    }

    public function test_consented_location_is_stored_with_source_rider_and_audit_without_coordinates(): void
    {
        $profile = $this->makeRider(true);

        $point = app(RiderLocationService::class)->record($profile, [
            'latitude' => 23.7805,
            'longitude' => 90.4075,
        ], $this->httpRequest());

        $this->assertSame('rider', $point->source);
        $this->assertSame((int) $profile->employee_id, (int) $point->employee_id);
        $this->assertNull($point->field_visit_id);
        $this->assertSame((int) $this->admin->company_id, (int) $point->company_id);

        $audit = AuditEvent::query()
            ->where('action', 'sales.rider_location_recorded')
            ->where('entity_id', $profile->id)
            ->firstOrFail();

        // Privacy: the audit trail records THAT a position landed, never WHERE.
        $json = json_encode($audit->after);
        $this->assertStringNotContainsString('23.7805', $json);
        $this->assertStringNotContainsString('latitude', $json);
        $this->assertSame($point->id, $audit->after['gps_point_id']);
        $this->assertTrue($audit->after['gps_consent']);
    }

    public function test_out_of_range_coordinates_are_refused(): void
    {
        $profile = $this->makeRider(true);

        try {
            app(RiderLocationService::class)->record($profile, [
                'latitude' => 91.5,
                'longitude' => 90.4,
            ], $this->httpRequest());
            $this->fail('Out-of-range coordinates must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('out of range', $e->getMessage());
        }

        $this->assertSame(0, GpsPoint::query()->count());
    }

    public function test_rider_self_may_post_their_own_location_only_with_consent(): void
    {
        $riderUser = $this->makeUser(['name' => 'Consenting Rider']);
        $riderUser->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($riderUser);

        $withConsent = $this->makeRider(true, (int) $riderUser->id);

        // Same rider, consent later revoked.
        $withConsent->gps_consent = false;
        $withConsent->save();

        // Consent off → explicit refusal, still zero rows.
        $this->actingAs($riderUser)
            ->post(route('sales.delivery.riders.location', $withConsent), [
                'latitude' => 23.7805,
                'longitude' => 90.4075,
            ])
            ->assertSessionHasErrors('location');

        $this->assertSame(0, GpsPoint::query()->where('source', 'rider')->count());

        // Consent back on → stored.
        $withConsent->gps_consent = true;
        $withConsent->save();

        $this->actingAs($riderUser)
            ->post(route('sales.delivery.riders.location', $withConsent), [
                'latitude' => 23.7805,
                'longitude' => 90.4075,
            ])
            ->assertRedirect(route('sales.delivery.riders.gps'));

        $this->assertSame(1, GpsPoint::query()
            ->where('source', 'rider')
            ->where('employee_id', $withConsent->employee_id)
            ->count());
    }

    public function test_another_riders_location_requires_the_permission(): void
    {
        $profile = $this->makeRider(true);

        $stranger = $this->makeUser(['name' => 'Nosy Stranger']);
        $stranger->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($stranger);

        $this->actingAs($stranger)
            ->post(route('sales.delivery.riders.location', $profile), [
                'latitude' => 23.7805,
                'longitude' => 90.4075,
            ])
            ->assertForbidden();

        $this->assertSame(0, GpsPoint::query()->count());
    }

    public function test_gps_screen_states_the_truth_and_is_gated(): void
    {
        $consented = $this->makeRider(true);
        $unconsented = $this->makeRider(false);

        $point = app(RiderLocationService::class)->record($consented, [
            'latitude' => 23.7805,
            'longitude' => 90.4075,
        ], $this->httpRequest());

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.riders.gps'));
        $response->assertOk();
        $response->assertSee('approved');
        $response->assertSee('not approved');
        $response->assertSee('23.7805000');                  // consented rider's real position
        $response->assertSee('Not tracked — consent not approved');

        // The unconsented rider never leaks coordinates anywhere.
        $response->assertDontSee('90.4100000');

        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.riders.gps'))
            ->assertForbidden();
    }

    public function test_gps_screen_without_a_roster_says_so_and_offers_no_form(): void
    {
        $response = $this->actingAs($this->admin)->get(route('sales.delivery.riders.gps'));
        $response->assertOk();
        $response->assertSee('No riders on this roster yet');
        $response->assertDontSee('name="rider_id"', false);
        $response->assertDontSee('name="latitude"', false);
    }
}

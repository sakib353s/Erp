<?php

namespace Tests\Feature;

use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Services\RouteOptimizerService;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Masters\Courier;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\District;
use App\Domain\People\Employee;
use App\Domain\Sales\GpsPoint;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-94 Route Optimization at GET /app/sales/delivery/routes behind
 * sales.delivery.routes: a local, deterministic stop order (zone →
 * district sort → shipment id) with an explanation for every
 * position. No external routing/AI service is called, no distance is
 * invented (stops carry no coordinates), and rider positions come
 * only from consent-gated gps_points.
 */
class RouteOptimizerTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'RTOX',
            'name' => 'Route Optimizer Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    /** @return array{0: District, 1: District, 2: District} */
    protected function districts(): array
    {
        $districts = District::query()->orderBy('sort')->orderBy('id')->limit(3)->get();
        $this->assertCount(3, $districts);

        return [$districts[0], $districts[1], $districts[2]];
    }

    protected function makeZone(string $code, string $name, int $districtId): DeliveryZone
    {
        $zone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);

        DB::table('delivery_zone_district')->insert([
            'delivery_zone_id' => $zone->id,
            'district_id' => $districtId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $zone;
    }

    protected function makeEmployee(string $suffix): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'RTO-'.$suffix,
            'first_name' => 'Route',
            'last_name' => $suffix,
            'full_name' => 'Rider '.$suffix,
            'designation' => 'Delivery Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
    }

    protected function makeRiderProfile(Employee $employee, bool $consent): RiderProfile
    {
        return app(CreateRiderProfile::class)
            ->handle($employee, ['gps_consent' => $consent], $this->httpRequest());
    }

    /**
     * @param  array{district: District, order_no: string, employee?: Employee|null, rider_name?: string, assignment_status?: string, shipment_status?: string}  $stop
     */
    protected function makeStop(array $stop): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'RTOC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Route Customer '.$stop['order_no'],
            'phone' => '01700000000',
            'district_id' => $stop['district']->id,
            'address_line1' => 'Street 1',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customerId,
            'order_no' => $stop['order_no'],
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'courier_id' => $this->courier->id,
            'status' => $stop['shipment_status'] ?? Shipment::STATUS_PENDING_DISPATCH,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rider_assignments')->insert([
            'company_id' => $this->admin->company_id,
            'shipment_id' => $shipmentId,
            'rider_name' => $stop['rider_name'] ?? ($stop['employee']->full_name ?? 'Route Rider'),
            'rider_employee_id' => isset($stop['employee']) ? $stop['employee']->id : null,
            'status' => $stop['assignment_status'] ?? 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shipmentId;
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__routes', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function sequences(array $plan): array
    {
        $sequences = [];

        foreach ($plan['groups'] as $group) {
            $sequences[$group['rider_label']] = array_column($group['stops'], 'order_no');
        }

        return $sequences;
    }

    public function test_plan_orders_stops_by_zone_then_district_then_shipment_deterministically(): void
    {
        [$withZoneA, $withZoneB, $unzoned] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);
        $this->makeZone('ZBE', 'Beta Zone', $withZoneB->id);

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);

        // Inserted deliberately out of order: the plan must not follow insertion
        // order — but within one zone/district the tiebreak IS shipment id,
        // so RTO-A2 (created third) legitimately precedes RTO-A1 (fourth).
        $this->makeStop(['district' => $unzoned, 'order_no' => 'RTO-Z1', 'employee' => $rider]);
        $this->makeStop(['district' => $withZoneB, 'order_no' => 'RTO-B1', 'employee' => $rider]);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-A2', 'employee' => $rider]);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-A1', 'employee' => $rider]);

        $service = app(RouteOptimizerService::class);
        $companyId = (int) $this->admin->company_id;

        $first = $this->sequences($service->plan($companyId));
        $second = $this->sequences($service->plan($companyId));

        $this->assertSame(
            ['Rider ALPHA' => ['RTO-A2', 'RTO-A1', 'RTO-B1', 'RTO-Z1']],
            $first,
        );
        $this->assertSame($first, $second, 'The plan must be deterministic across runs.');
    }

    public function test_plan_explains_every_stop_and_states_the_local_method(): void
    {
        [$withZoneA, $withZoneB, $unzoned] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);

        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-A1', 'employee' => $rider]);
        $this->makeStop(['district' => $unzoned, 'order_no' => 'RTO-Z1', 'employee' => $rider]);

        $plan = app(RouteOptimizerService::class)->plan((int) $this->admin->company_id);

        $reasons = array_column($plan['groups'][0]['stops'], 'reason');
        $this->assertStringContainsString('Zone "Alpha Zone" group', $reasons[0]);
        $this->assertStringContainsString('district sort', $reasons[0]);
        $this->assertStringContainsString('No delivery zone', $reasons[1]);
        $this->assertStringContainsString('district sort', $reasons[1]);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();
        $response->assertSee(RouteOptimizerService::METHOD_NOTE);
        $response->assertSee('Why here');
        $response->assertSee('Zone "Alpha Zone" group');
        $response->assertSee('No delivery zone');
    }

    public function test_no_distances_are_invented_and_no_km_figures_appear(): void
    {
        [$withZoneA] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-A1', 'employee' => $rider]);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();
        $response->assertSee(RouteOptimizerService::DISTANCE_NOTE);
        $response->assertSee('distances are not computed');
        $response->assertDontSee('km');
        $response->assertDontSee('miles');
    }

    public function test_rider_position_states_tell_the_truth_about_gps_consent(): void
    {
        [$withZoneA] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);

        $sharing = $this->makeEmployee('SHARING');
        $this->makeRiderProfile($sharing, true);
        GpsPoint::query()->create([
            'company_id' => $this->admin->company_id,
            'employee_id' => $sharing->id,
            'latitude' => 23.7805000,
            'longitude' => 90.4075000,
            'captured_at' => now()->subMinutes(10),
            'source' => 'rider',
        ]);

        $silent = $this->makeEmployee('SILENT');
        $this->makeRiderProfile($silent, true);

        $noSharing = $this->makeEmployee('NOSHARE');
        $this->makeRiderProfile($noSharing, false);

        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-S1', 'employee' => $sharing]);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-S2', 'employee' => $silent]);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-S3', 'employee' => $noSharing]);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-S4', 'rider_name' => 'Legacy Name Rider']);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();

        $point = GpsPoint::query()->orderByDesc('captured_at')->first();
        $response->assertSee('Last shared position '.$point->captured_at->format('Y-m-d H:i').'.');
        $response->assertSee('Sharing approved — no position recorded yet.');
        $response->assertSee('GPS sharing not approved — position unknown.');
        $response->assertSee('Not on the rider roster (name-only assignment) — no position available.');

        // Coordinates are never rendered on this screen.
        $response->assertDontSee('23.7805000');
        $response->assertDontSee('90.4075000');
    }

    public function test_closed_shipments_are_excluded_and_unassigned_shipments_are_counted_not_routed(): void
    {
        [$withZoneA] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);

        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-OPEN', 'employee' => $rider]);
        $this->makeStop([
            'district' => $withZoneA,
            'order_no' => 'RTO-DONE',
            'employee' => $rider,
            'shipment_status' => Shipment::STATUS_DELIVERED,
        ]);

        // Open shipment with no rider assignment at all: counted, never routed.
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'RTOC-UNASSIGNED',
            'name' => 'Unassigned Customer',
            'district_id' => $withZoneA->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customerId,
            'order_no' => 'RTO-UNASSIGNED',
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shipments')->insert([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'courier_id' => $this->courier->id,
            'status' => Shipment::STATUS_PENDING_DISPATCH,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(RouteOptimizerService::class)->plan((int) $this->admin->company_id);

        $this->assertSame(['RTO-OPEN'], $this->sequences($plan)['Rider ALPHA']);
        $this->assertSame(1, $plan['totals']['closed_shipments_excluded']);
        $this->assertSame(1, $plan['totals']['unassigned_open_shipments']);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();
        $response->assertSee('1 open shipment(s) with no rider assignment');
        $response->assertSee('1 assigned shipment(s) already delivered or failed (excluded)');
        $response->assertDontSee('RTO-DONE');
        $response->assertDontSee('RTO-UNASSIGNED');
    }

    public function test_route_screen_requires_sales_delivery_routes(): void
    {
        $denied = $this->makeUser(['name' => 'Route Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.routes.index'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Route Planner']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.routes'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.delivery.routes.index'))
            ->assertOk();
    }

    public function test_route_plan_is_company_scoped(): void
    {
        [$withZoneA] = $this->districts();

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-MINE', 'employee' => $rider]);

        // Another company's identical route rows must never appear here.
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Routes Ltd',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourierId = DB::table('couriers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCustomerId = DB::table('customers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW-C1',
            'name' => 'Shadow Customer',
            'district_id' => $withZoneA->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'customer_id' => $shadowCustomerId,
            'order_no' => 'RTO-SHADOW',
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowShipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $shadowId,
            'sales_order_id' => $shadowOrderId,
            'courier_id' => $shadowCourierId,
            'status' => Shipment::STATUS_PENDING_DISPATCH,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rider_assignments')->insert([
            'company_id' => $shadowId,
            'shipment_id' => $shadowShipmentId,
            'rider_name' => 'Shadow Rider',
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(RouteOptimizerService::class)->plan((int) $this->admin->company_id);

        $this->assertSame(['Rider ALPHA' => ['RTO-MINE']], $this->sequences($plan));
        $this->assertSame(1, $plan['totals']['stops']);
        $this->assertSame(0, $plan['totals']['unassigned_open_shipments']);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();
        $response->assertSee('RTO-MINE');
        $response->assertDontSee('RTO-SHADOW');
    }

    public function test_route_screen_with_no_assignments_is_honest(): void
    {
        $response = $this->actingAs($this->admin)->get(route('sales.delivery.routes.index'));
        $response->assertOk();
        $response->assertSee('No assigned stops to route yet');
        $response->assertSee('0 stop(s) across 0 rider(s)');
    }

    public function test_route_optimizer_makes_no_external_requests(): void
    {
        [$withZoneA] = $this->districts();
        $this->makeZone('ZAL', 'Alpha Zone', $withZoneA->id);

        $rider = $this->makeEmployee('ALPHA');
        $this->makeRiderProfile($rider, false);
        $this->makeStop(['district' => $withZoneA, 'order_no' => 'RTO-A1', 'employee' => $rider]);

        Http::preventStrayRequests();

        // Any outbound HTTP/AI call would throw and fail this request.
        $this->actingAs($this->admin)
            ->get(route('sales.delivery.routes.index'))
            ->assertOk();
    }
}

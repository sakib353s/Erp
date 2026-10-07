<?php

namespace Tests\Feature;

use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\Actions\RecordRiderCodCollection;
use App\Domain\Delivery\CodReconciliation;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-97a COD collection tracking at GET /app/sales/delivery/cod
 * behind sales.delivery.cod:
 *
 *  - real rider collections with rider/shipment/order context and
 *    honest cash totals (collected / reconciled / outstanding /
 *    remitted — sums of actual rows only);
 *  - per-rider split of reconciled vs pending vs outstanding cash;
 *  - three distinct collection states (outstanding, pending approval,
 *    reconciled) rendered truthfully;
 *  - shadow-company collections never leak into the report;
 *  - the screen is gated by sales.delivery.cod (holding the
 *    reconcile permission alone does not open it).
 */
class CodCollectionTrackingTest extends TestCase
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
        $request = Request::create('/__cod-tracking', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeRider(): RiderProfile
    {
        $employee = Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'TRK-'.Employee::query()->count(),
            'first_name' => 'Track',
            'full_name' => 'Track Rider '.Employee::query()->count(),
            'employment_status' => 'active',
            'status' => 'active',
        ]);

        return app(CreateRiderProfile::class)
            ->handle($employee, [], $this->httpRequest());
    }

    protected function makeAssignment(RiderProfile $profile, string $status): RiderAssignment
    {
        $orderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $this->admin->company_id,
            'order_no' => 'TRK-'.uniqid(),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courierId = DB::table('couriers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'TRKC'.uniqid(),
            'name' => 'COD Tracking Courier',
            'configuration_status' => 'not_configured',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shipment = Shipment::query()->create([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'courier_id' => $courierId,
            'status' => Shipment::STATUS_PENDING_DISPATCH,
        ]);

        return RiderAssignment::query()->create([
            'company_id' => $this->admin->company_id,
            'shipment_id' => $shipment->id,
            'rider_name' => $profile->employee->full_name,
            'rider_employee_id' => $profile->employee_id,
            'status' => $status,
            'assigned_by' => $this->admin->id,
        ]);
    }

    protected function collect(RiderAssignment $assignment, float $amount): RiderCodCollection
    {
        return app(RecordRiderCodCollection::class)
            ->handle($assignment, ['amount' => $amount], $this->httpRequest());
    }

    /** Fixture: a reconciliation row without going through the action. */
    protected function makeReconciliation(
        RiderCodCollection $collection,
        string $status,
        float $remitted,
    ): CodReconciliation {
        $row = CodReconciliation::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'rider_employee_id' => $collection->rider_employee_id,
            'remitted_amount' => number_format($remitted, 2, '.', ''),
            'cash_total' => number_format((float) $collection->amount, 2, '.', ''),
            'variance' => number_format($remitted - (float) $collection->amount, 2, '.', ''),
            'status' => $status,
            'remitted_at' => now(),
        ]);

        RiderCodCollection::query()
            ->where('id', $collection->id)
            ->update(['cod_reconciliation_id' => $row->id]);

        return $row;
    }

    public function test_screen_lists_real_collections_with_honest_totals(): void
    {
        $profile = $this->makeRider();
        $assignment = $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);
        $collection = $this->collect($assignment, 1250.50);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.delivery.cod.index'));

        $response->assertOk();
        $response->assertSee($profile->employee->full_name);
        $response->assertSee('Shipment #'.$assignment->shipment_id);
        $response->assertSee('1,250.50'); // collection row + collected total
        $response->assertSee('0.00');     // nothing reconciled or remitted yet
        $response->assertSee('Outstanding');

        $this->assertSame(1, RiderCodCollection::query()
            ->where('company_id', $this->admin->company_id)
            ->count());
        $this->assertSame(0, CodReconciliation::query()->count());
    }

    public function test_empty_state_is_truthful(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.delivery.cod.index'))
            ->assertOk()
            ->assertSee('No COD collections yet — riders record cash as it is collected.')
            ->assertSee('No reconciliations yet — the first remittance match will appear here.');
    }

    public function test_per_rider_totals_split_reconciled_pending_and_outstanding(): void
    {
        $riderA = $this->makeRider();
        $riderB = $this->makeRider();

        $a1 = $this->collect(
            $this->makeAssignment($riderA, RiderAssignment::STATUS_ACCEPTED),
            100,
        );
        $a2 = $this->collect(
            $this->makeAssignment($riderA, RiderAssignment::STATUS_ACCEPTED),
            200,
        );
        $b1 = $this->collect(
            $this->makeAssignment($riderB, RiderAssignment::STATUS_ACCEPTED),
            50,
        );

        // a1 fully reconciled, a2 stuck in pending approval, b1 outstanding.
        $this->makeReconciliation($a1, CodReconciliation::STATUS_RECONCILED, 100);
        $this->makeReconciliation($a2, CodReconciliation::STATUS_PENDING, 200);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.delivery.cod.index'));

        $response->assertOk();
        // Cash position: collected 350, reconciled 100, outstanding 250, remitted 100.
        $response->assertSee('350.00');
        $response->assertSee('250.00');
        $response->assertSee('100.00');
        // Rider A: collected 300, reconciled 100, pending 200, outstanding 200.
        $response->assertSee('300.00');
        $response->assertSee('200.00');
        // Rider B: 50 collected, 50 outstanding.
        $response->assertSee('50.00');
    }

    public function test_collection_states_render_as_distinct_badges(): void
    {
        $profile = $this->makeRider();
        $accepted = fn () => $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);

        $this->collect($accepted(), 60); // outstanding
        $pending = $this->collect($accepted(), 70);
        $settled = $this->collect($accepted(), 80);

        $this->makeReconciliation($pending, CodReconciliation::STATUS_PENDING, 70);
        $this->makeReconciliation($settled, CodReconciliation::STATUS_RECONCILED, 80);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.delivery.cod.index'));

        $response->assertOk();
        $response->assertSee('Outstanding');
        $response->assertSee('Pending approval');
        $response->assertSee('Reconciled');
        $response->assertSee('Matched'); // zero variance on the reconciled row
    }

    public function test_shadow_company_collections_never_leak_into_the_report(): void
    {
        $profile = $this->makeRider();
        $assignment = $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);
        $this->collect($assignment, 750);

        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow COD Tracking Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowEmployee = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDWT',
            'first_name' => 'Shadow',
            'full_name' => 'Shadow Cod Tracker',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        DB::table('rider_cod_collections')->insert([
            'company_id' => $shadowId,
            'rider_assignment_id' => $assignment->id,
            'rider_employee_id' => $shadowEmployee->id,
            'amount' => 99999.99,
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.delivery.cod.index'));

        $response->assertOk();
        $response->assertSee('750.00');
        $response->assertDontSee('99,999.99');
        $response->assertDontSee('Shadow Cod Tracker');
    }

    public function test_screen_is_gated_by_sales_delivery_cod(): void
    {
        // Portal access only: closed.
        $denied = $this->makeUser();
        $deniedRole = $this->roleWith(['portal.erp.access']);
        $denied->roles()->attach($deniedRole->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.cod.index'))
            ->assertForbidden();

        // Holding the reconcile permission alone does not open the screen.
        $reconciler = $this->makeUser();
        $reconcilerRole = $this->roleWith([
            'portal.erp.access',
            'sales.delivery.cod.reconcile',
        ]);
        $reconciler->roles()->attach($reconcilerRole->id);
        app(PermissionCatalog::class)->invalidate($reconciler);

        $this->actingAs($reconciler)
            ->get(route('sales.delivery.cod.index'))
            ->assertForbidden();

        // With the view permission: open.
        $granted = $this->makeUser();
        $grantedRole = $this->roleWith(['portal.erp.access', 'sales.delivery.cod']);
        $granted->roles()->attach($grantedRole->id);
        app(PermissionCatalog::class)->invalidate($granted);

        $this->actingAs($granted)
            ->get(route('sales.delivery.cod.index'))
            ->assertOk();
    }
}

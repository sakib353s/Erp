<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\CreateRiderProfile;
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
 * 02-93d Rider COD collection at GET|POST
 * /app/sales/delivery/rider-cod behind sales.delivery.riders:
 * cash recorded only against ACCEPTED assignments (the rider
 * confirmed the handoff), positive amounts, full audit, no
 * accounting posting (reconciliation is 02-97), company scoping and
 * permission gates.
 */
class RiderCodCollectionTest extends TestCase
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
        $request = Request::create('/__rider-cod', 'POST', [], [], [], [
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
            'code' => 'COD-'.Employee::query()->count(),
            'first_name' => 'Cod',
            'full_name' => 'Cod Rider',
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
            'order_no' => 'COD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courierId = DB::table('couriers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'CODC'.uniqid(),
            'name' => 'COD Test Courier',
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

    public function test_record_cod_against_accepted_assignment_with_audit(): void
    {
        $profile = $this->makeRider();
        $assignment = $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);

        $response = $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $assignment->id,
            'amount' => 1250.50,
            'notes' => 'Collected from customer at door',
        ]);

        $response->assertRedirect(route('sales.delivery.rider-cod.index'));
        $response->assertSessionHas('status');

        $collection = RiderCodCollection::query()
            ->where('rider_assignment_id', $assignment->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(1250.50, (float) $collection->amount, 0.001);
        $this->assertSame((int) $profile->employee_id, (int) $collection->rider_employee_id);
        $this->assertSame((int) $this->admin->id, (int) $collection->recorded_by);
        $this->assertNotNull($collection->collected_at);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_cod_collected')
            ->where('entity_id', $collection->id)
            ->count());

        // Record only: no journal entry from a COD collection (02-97 posts).
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_pending_and_declined_assignments_are_refused(): void
    {
        $profile = $this->makeRider();
        $pending = $this->makeAssignment($profile, RiderAssignment::STATUS_PENDING);
        $declined = $this->makeAssignment($profile, RiderAssignment::STATUS_DECLINED);

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $pending->id,
            'amount' => 100,
        ])->assertSessionHasErrors('rider_assignment_id');

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $declined->id,
            'amount' => 100,
        ])->assertSessionHasErrors('rider_assignment_id');

        $this->assertSame(0, RiderCodCollection::query()->count());
    }

    public function test_name_only_assignment_is_refused(): void
    {
        // Legacy shape: rider name recorded, no roster link (pre-02-93).
        $assignment = $this->makeAssignment($this->makeRider(), RiderAssignment::STATUS_ACCEPTED);
        DB::table('rider_assignments')
            ->where('id', $assignment->id)
            ->update(['rider_employee_id' => null]);
        $assignment = $assignment->fresh();

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $assignment->id,
            'amount' => 100,
        ])->assertSessionHasErrors('rider_assignment_id');

        $this->assertSame(0, RiderCodCollection::query()->count());
    }

    public function test_amount_must_be_positive(): void
    {
        $profile = $this->makeRider();
        $assignment = $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $assignment->id,
            'amount' => 0,
        ])->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $assignment->id,
            'amount' => -50,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, RiderCodCollection::query()->count());
    }

    public function test_index_lists_collections_with_total_and_collectable_assignments(): void
    {
        $profile = $this->makeRider();
        $accepted = $this->makeAssignment($profile, RiderAssignment::STATUS_ACCEPTED);
        $pending = $this->makeAssignment($profile, RiderAssignment::STATUS_PENDING);

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => $accepted->id,
            'amount' => 750,
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.rider-cod.index'));
        $response->assertOk();
        $response->assertSee('Total 750.00');
        $response->assertSee($profile->employee->full_name);

        // Shadow company collections never leak into the list or the total.
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow COD Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowEmployee = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'first_name' => 'Shadow',
            'full_name' => 'Shadow Cod',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        DB::table('rider_cod_collections')->insert([
            'company_id' => $shadowId,
            'rider_assignment_id' => $accepted->id,
            'rider_employee_id' => $shadowEmployee->id,
            'amount' => 99999.99,
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.rider-cod.index'));
        $response->assertOk();
        $response->assertDontSee('99,999.99');
        $response->assertSee('Total 750.00');
        $response->assertDontSee('Shadow Cod');

        // The collectable dropdown shows only accepted assignments.
        $content = (string) $response->getContent();
        $selectStart = strpos($content, 'id="rider_assignment_id"');
        $this->assertNotFalse($selectStart);
        $select = substr($content, $selectStart, 3000);
        $this->assertStringContainsString('#'.$accepted->id.' —', $select);
        $this->assertStringNotContainsString('#'.$pending->id.' —', $select);
    }

    public function test_routes_require_sales_delivery_riders(): void
    {
        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.delivery.rider-cod.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('sales.delivery.rider-cod.store'), [
            'rider_assignment_id' => 1,
            'amount' => 10,
        ])->assertForbidden();

        $allowed = $this->makeUser(['name' => 'COD Recorder']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.riders'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('sales.delivery.rider-cod.index'))->assertOk();
    }
}

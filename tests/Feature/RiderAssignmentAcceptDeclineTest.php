<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use App\Domain\People\Employee;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-93b AssignRider + RespondToRiderAssignment at
 * /app/sales/delivery/rider-assignments: assignment starts pending
 * with a truthful in-app request to the linked rider, the rider (or a
 * sales.delivery.riders holder on their behalf) accepts/declines
 * exactly once, audits every step, and never moves stock (handover
 * stays the dispatch step, 02-89).
 */
class RiderAssignmentAcceptDeclineTest extends TestCase
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
        $request = Request::create('/__rider-assign', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** @return array{0: RiderProfile, 1: Employee, 2: User} */
    protected function makeRiderWithUser(): array
    {
        $employee = Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'RDR-U'.Employee::query()->count(),
            'first_name' => 'Rida',
            'last_name' => 'Rahman',
            'full_name' => 'Rida Rahman',
            'employment_status' => 'active',
            'status' => 'active',
        ]);

        $user = $this->makeUser(['name' => 'Rida Rahman']);
        $employee->user_id = $user->id;
        $employee->save();

        $profile = app(CreateRiderProfile::class)
            ->handle($employee, ['vehicle_type' => 'Motorbike'], $this->httpRequest());

        return [$profile, $employee, $user];
    }

    protected function makeShipment(): Shipment
    {
        $orderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $this->admin->company_id,
            'order_no' => 'RID-'.uniqid(),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courierId = DB::table('couriers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'RIDC'.uniqid(),
            'name' => 'Rider Test Courier',
            'configuration_status' => 'not_configured',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shipment::query()->create([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'courier_id' => $courierId,
            'status' => Shipment::STATUS_PENDING_DISPATCH,
        ]);
    }

    public function test_assign_starts_pending_notifies_linked_rider_and_audits(): void
    {
        [$profile, , $riderUser] = $this->makeRiderWithUser();
        $shipment = $this->makeShipment();

        $response = $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ]);

        $response->assertRedirect(route('sales.delivery.rider-assignments.index'));
        $response->assertSessionHas('status');

        $assignment = RiderAssignment::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(RiderAssignment::STATUS_PENDING, $assignment->status);
        $this->assertSame($profile->employee_id, $assignment->rider_employee_id);
        $this->assertSame('Rida Rahman', $assignment->rider_name);
        $this->assertNull($assignment->responded_at);
        $this->assertNull($assignment->responded_by);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_assigned')
            ->where('entity_id', $assignment->id)
            ->count());

        $notification = Notification::query()
            ->where('event_type', 'sales.rider_assignment')
            ->where('user_id', $riderUser->id)
            ->firstOrFail();
        $this->assertStringContainsString('awaiting your response', $notification->title);

        // Assignment never moves stock — handover is dispatch (02-89).
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_rider_self_accepts_then_a_second_response_is_refused(): void
    {
        [$profile, , $riderUser] = $this->makeRiderWithUser();
        $shipment = $this->makeShipment();
        $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ])->assertSessionHasNoErrors();

        $assignment = RiderAssignment::query()->where('shipment_id', $shipment->id)->firstOrFail();

        // Rider-self: only portal access, no sales.delivery.riders.
        $riderUser->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($riderUser);

        $this->actingAs($riderUser)
            ->post(route('sales.delivery.rider-assignments.accept', $assignment))
            ->assertRedirect(route('sales.delivery.rider-assignments.index'));

        $assignment = $assignment->fresh();
        $this->assertSame(RiderAssignment::STATUS_ACCEPTED, $assignment->status);
        $this->assertNotNull($assignment->responded_at);
        $this->assertSame((int) $riderUser->id, (int) $assignment->responded_by);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_assignment_accepted')
            ->where('entity_id', $assignment->id)
            ->count());

        // Exactly once: a second response lands as an explicit form error.
        $this->actingAs($riderUser)
            ->post(route('sales.delivery.rider-assignments.decline', $assignment))
            ->assertSessionHasErrors('assignment');

        $this->assertSame(RiderAssignment::STATUS_ACCEPTED, $assignment->fresh()->status);
        // The refused response leaves no audit trail of a decline that never happened.
        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'sales.rider_assignment_declined')
            ->where('entity_id', $assignment->id)
            ->count());
    }

    public function test_rider_declines_and_other_riders_cannot_respond(): void
    {
        [$profile, , $riderUser] = $this->makeRiderWithUser();

        // A second rider with no link to this assignment.
        $outsiderEmployee = Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'RDR-OUT',
            'first_name' => 'Other',
            'full_name' => 'Other Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        $outsider = $this->makeUser(['name' => 'Other Rider']);
        $outsiderEmployee->user_id = $outsider->id;
        $outsiderEmployee->save();
        $outsider->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($outsider);

        $shipment = $this->makeShipment();
        $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ])->assertSessionHasNoErrors();

        $assignment = RiderAssignment::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $this->actingAs($outsider)
            ->post(route('sales.delivery.rider-assignments.decline', $assignment))
            ->assertForbidden();

        $this->assertSame(RiderAssignment::STATUS_PENDING, $assignment->fresh()->status);

        $riderUser->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($riderUser);

        $this->actingAs($riderUser)
            ->post(route('sales.delivery.rider-assignments.decline', $assignment))
            ->assertRedirect(route('sales.delivery.rider-assignments.index'));

        $this->assertSame(RiderAssignment::STATUS_DECLINED, $assignment->fresh()->status);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_assignment_declined')
            ->where('entity_id', $assignment->id)
            ->count());
    }

    public function test_permission_holder_responds_on_the_riders_behalf(): void
    {
        [$profile, , $riderUser] = $this->makeRiderWithUser();
        $shipment = $this->makeShipment();
        $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ])->assertSessionHasNoErrors();

        $assignment = RiderAssignment::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $dispatcher = $this->makeUser(['name' => 'Dispatcher']);
        $dispatcher->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.riders'])->id);
        app(PermissionCatalog::class)->invalidate($dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('sales.delivery.rider-assignments.accept', $assignment))
            ->assertRedirect(route('sales.delivery.rider-assignments.index'));

        $assignment = $assignment->fresh();
        $this->assertSame(RiderAssignment::STATUS_ACCEPTED, $assignment->status);
        $this->assertSame((int) $dispatcher->id, (int) $assignment->responded_by);
        $this->assertNotSame((int) $riderUser->id, (int) $assignment->responded_by);
    }

    public function test_duplicate_active_assignment_is_refused_and_no_stock_moves(): void
    {
        [$profile] = $this->makeRiderWithUser();
        $shipment = $this->makeShipment();

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('sales.delivery.rider-assignments.store'), [
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $profile->id,
        ])->assertSessionHasErrors('shipment_id');

        $this->assertSame(1, RiderAssignment::query()->where('shipment_id', $shipment->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->fresh()->status);
    }

    public function test_assign_routes_gated_and_foreign_assignment_is_404(): void
    {
        [$profile] = $this->makeRiderWithUser();
        $shipment = $this->makeShipment();

        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.rider-assignments.index'))
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.delivery.rider-assignments.store'), [
                'shipment_id' => $shipment->id,
                'rider_profile_id' => $profile->id,
            ])
            ->assertForbidden();

        // Foreign assignment: 404 on respond, untouched.
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Riders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowAssignment = DB::table('rider_assignments')->insertGetId([
            'company_id' => $shadowId,
            'shipment_id' => $shipment->id,
            'rider_name' => 'Foreign',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.rider-assignments.accept', $shadowAssignment))
            ->assertNotFound();

        $this->assertSame('pending', DB::table('rider_assignments')
            ->where('id', $shadowAssignment)
            ->value('status'));
    }
}

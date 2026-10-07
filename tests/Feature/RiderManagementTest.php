<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\RiderProfile;
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
 * 02-93a Rider roster at GET|POST /app/sales/delivery/riders behind
 * sales.delivery.riders: employee-backed profiles (one per employee
 * per company), consent/availability toggles, company scoping on the
 * list and the update binding, and no fake roster rows.
 */
class RiderManagementTest extends TestCase
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
        $request = Request::create('/__riders', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeEmployee(string $suffix, array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'RDR-'.$suffix,
            'first_name' => 'Rider',
            'last_name' => $suffix,
            'full_name' => 'Rider '.$suffix,
            'designation' => 'Delivery Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ], $overrides));
    }

    public function test_add_rider_to_roster_with_audit(): void
    {
        $employee = $this->makeEmployee('ALPHA');

        $response = $this->actingAs($this->admin)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $employee->id,
            'vehicle_type' => 'Motorbike',
            'vehicle_plate' => 'DHK-M-1122',
        ]);

        $response->assertRedirect(route('sales.delivery.riders.index'));
        $response->assertSessionHas('status');

        $profile = RiderProfile::query()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame((int) $this->admin->company_id, (int) $profile->company_id);
        $this->assertSame('Motorbike', $profile->vehicle_type);
        $this->assertSame('DHK-M-1122', $profile->vehicle_plate);
        $this->assertTrue($profile->is_available);
        $this->assertTrue($profile->is_active);
        $this->assertFalse($profile->gps_consent); // consent is opt-in, never defaulted on

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_profile_created')
            ->where('entity_type', 'rider_profile')
            ->where('entity_id', $profile->id)
            ->count());
    }

    public function test_readding_same_employee_updates_the_profile_instead_of_duplicating(): void
    {
        $employee = $this->makeEmployee('BETA');

        $this->actingAs($this->admin)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $employee->id,
            'vehicle_type' => 'Cycle',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $employee->id,
            'vehicle_type' => 'Motorbike',
            'vehicle_plate' => 'DHK-M-2233',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, RiderProfile::query()->where('employee_id', $employee->id)->count());

        $profile = RiderProfile::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('Motorbike', $profile->vehicle_type);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_profile_updated')
            ->where('entity_id', $profile->id)
            ->count());
    }

    public function test_consent_and_availability_toggles(): void
    {
        $employee = $this->makeEmployee('GAMMA');
        $profile = app(CreateRiderProfile::class)
            ->handle($employee, ['vehicle_type' => 'Van'], $this->httpRequest());

        $this->assertFalse($profile->gps_consent);
        $this->assertTrue($profile->is_available);

        $this->actingAs($this->admin)->put(route('sales.delivery.riders.update', $profile), [
            'vehicle_type' => 'Van',
            'vehicle_plate' => '',
            'gps_consent' => 1,
            'is_available' => 0,
            'is_active' => 1,
        ])->assertRedirect(route('sales.delivery.riders.index'));

        $profile = $profile->fresh();
        $this->assertTrue($profile->gps_consent);
        $this->assertFalse($profile->is_available);
        $this->assertTrue($profile->is_active);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.rider_profile_updated')
            ->where('entity_id', $profile->id)
            ->count());
    }

    public function test_roster_index_scopes_to_company_and_hides_rostered_employees_from_the_picker(): void
    {
        $mine = $this->makeEmployee('MINE');
        $onRoster = $this->makeEmployee('ROSTERED');
        $profile = app(CreateRiderProfile::class)
            ->handle($onRoster, [], $this->httpRequest());

        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Riders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowEmployee = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'first_name' => 'Shadow',
            'full_name' => 'Shadow Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        DB::table('rider_profiles')->insert([
            'company_id' => $shadowId,
            'employee_id' => $shadowEmployee->id,
            'is_available' => 1,
            'is_active' => 1,
            'gps_consent' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('sales.delivery.riders.index'));
        $response->assertOk();
        $response->assertSee($profile->employee->full_name); // roster table
        $response->assertDontSee('Shadow Rider');             // company-scoped

        // The employee picker never re-offers someone already rostered.
        $content = (string) $response->getContent();
        $pickerStart = strpos($content, 'id="employee_id"');
        $this->assertNotFalse($pickerStart);
        $picker = substr($content, $pickerStart, 2000);
        $this->assertStringContainsString($mine->full_name, $picker);
        $this->assertStringNotContainsString($onRoster->full_name, $picker);
    }

    public function test_foreign_company_employee_is_rejected(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Employees Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreign = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'FORE',
            'first_name' => 'Foreign',
            'full_name' => 'Foreign Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $foreign->id,
        ])->assertSessionHasErrors('employee_id');

        $this->assertSame(0, RiderProfile::query()->count());
    }

    public function test_foreign_profile_update_is_404(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Update Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreign = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'FUPD',
            'first_name' => 'Foreign',
            'full_name' => 'Foreign Update',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        $profileId = DB::table('rider_profiles')->insertGetId([
            'company_id' => $shadowId,
            'employee_id' => $foreign->id,
            'is_available' => 1,
            'is_active' => 1,
            'gps_consent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put(route('sales.delivery.riders.update', $profileId), ['gps_consent' => 1])
            ->assertNotFound();

        $this->assertFalse((bool) DB::table('rider_profiles')->where('id', $profileId)->value('gps_consent'));
    }

    public function test_routes_require_sales_delivery_riders(): void
    {
        $employee = $this->makeEmployee('GATE');

        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.delivery.riders.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $employee->id,
        ])->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Rider Admin']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.riders'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('sales.delivery.riders.index'))->assertOk();
        $this->actingAs($allowed)->post(route('sales.delivery.riders.store'), [
            'employee_id' => $employee->id,
        ])->assertRedirect(route('sales.delivery.riders.index'));

        $this->assertSame(1, RiderProfile::query()->where('employee_id', $employee->id)->count());
    }
}

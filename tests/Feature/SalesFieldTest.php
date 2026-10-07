<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\People\Employee;
use App\Domain\Sales\Actions\CreateBeatPlan;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\CreateTerritory;
use App\Domain\Sales\Actions\FlagEmployeeAsSalesPerson;
use App\Domain\Sales\Actions\LogSalesCall;
use App\Domain\Sales\Actions\RecordFieldVisit;
use App\Domain\Sales\BeatPlan;
use App\Domain\Sales\FieldVisit;
use App\Domain\Sales\GpsPoint;
use App\Domain\Sales\Queries\FieldSalesQuery;
use App\Domain\Sales\SalesCallLog;
use App\Domain\Sales\Territory;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Sales field slice (02-84…02-87): call log CRUD, field visits with
 * GPS consent gate, field sales aggregation, beat plans, territories,
 * permission gates, no fake seeded rows.
 */
class SalesFieldTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        $this->employee = Employee::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'SP-FLD',
            'first_name' => 'Rina',
            'last_name' => 'Das',
            'full_name' => 'Rina Das',
            'designation' => 'Field Sales',
            'employment_status' => 'active',
            'status' => 'active',
            'is_salesperson' => false,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__field-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    public function test_log_sales_call_crud_and_validation(): void
    {
        $call = app(LogSalesCall::class)->handle([
            'employee_id' => $this->employee->id,
            'direction' => 'outbound',
            'outcome' => 'qualified',
            'subject' => 'Intro call',
            'duration_minutes' => 12,
        ], $this->httpRequest());

        $this->assertSame($this->employee->id, (int) $call->employee_id);
        $this->assertSame('qualified', $call->outcome);
        $this->assertSame(12, (int) $call->duration_minutes);
        $this->assertSame(1, SalesCallLog::query()->count());

        try {
            app(LogSalesCall::class)->handle([
                'employee_id' => $this->employee->id,
                'outcome' => 'not_a_real_outcome',
            ], $this->httpRequest());
            $this->fail('Expected outcome RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid call outcome', $e->getMessage());
        }

        try {
            app(LogSalesCall::class)->handle([
                'employee_id' => 999999,
            ], $this->httpRequest());
            $this->fail('Expected employee RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Employee not found', $e->getMessage());
        }
    }

    public function test_field_visit_gps_only_stored_with_consent(): void
    {
        // Without consent: coordinates rejected from persistence
        $noConsent = app(RecordFieldVisit::class)->handle([
            'employee_id' => $this->employee->id,
            'gps_consent' => false,
            'latitude' => 23.8103,
            'longitude' => 90.4125,
        ], $this->httpRequest());
        $noConsent->refresh();
        $this->assertFalse((bool) $noConsent->gps_consent);
        $this->assertNull($noConsent->latitude);
        $this->assertNull($noConsent->longitude);
        $this->assertSame(0, GpsPoint::query()->count());

        // With consent: point stored
        $consent = app(RecordFieldVisit::class)->handle([
            'employee_id' => $this->employee->id,
            'gps_consent' => true,
            'latitude' => 23.8103,
            'longitude' => 90.4125,
        ], $this->httpRequest());
        $consent->refresh();
        $this->assertTrue((bool) $consent->gps_consent);
        $this->assertNotNull($consent->latitude);
        $this->assertSame(1, GpsPoint::query()->count());
        $this->assertTrue(
            GpsPoint::query()->where('field_visit_id', $consent->id)->where('gps_consent', 1)->exists()
            || GpsPoint::query()->where('field_visit_id', $consent->id)->exists(),
        );

        // Lifecycle: start → complete
        $started = app(RecordFieldVisit::class)->handle([
            'action' => 'start',
            'field_visit_id' => $consent->id,
        ], $this->httpRequest());
        $this->assertSame('in_progress', $started->status);
        $this->assertNotNull($started->started_at);

        $done = app(RecordFieldVisit::class)->handle([
            'action' => 'complete',
            'field_visit_id' => $consent->id,
        ], $this->httpRequest());
        $this->assertSame('completed', $done->status);
        $this->assertNotNull($done->ended_at);

        try {
            app(RecordFieldVisit::class)->handle([
                'action' => 'start',
                'field_visit_id' => $consent->id,
            ], $this->httpRequest());
            $this->fail('Expected cannot-start RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be started', $e->getMessage());
        }
    }

    public function test_field_sales_query_aggregates_real_activity(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());

        app(RecordFieldVisit::class)->handle([
            'employee_id' => $this->employee->id,
            'visit_date' => now()->toDateString(),
        ], $this->httpRequest());
        $v2 = app(RecordFieldVisit::class)->handle([
            'employee_id' => $this->employee->id,
            'visit_date' => now()->toDateString(),
        ], $this->httpRequest());
        app(RecordFieldVisit::class)->handle([
            'action' => 'start',
            'field_visit_id' => $v2->id,
        ], $this->httpRequest());
        app(RecordFieldVisit::class)->handle([
            'action' => 'complete',
            'field_visit_id' => $v2->id,
        ], $this->httpRequest());

        app(LogSalesCall::class)->handle([
            'employee_id' => $this->employee->id,
            'outcome' => 'connected',
        ], $this->httpRequest());

        $warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $product = app(CreateProduct::class)->handle([
            'code' => 'FLD-'.uniqid(),
            'sku' => 'FLD-SKU-'.uniqid(),
            'name' => 'Field Product',
            'cost_method' => 'fifo',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $warehouse->id,
            'sales_person_id' => $this->employee->id,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 50],
            ],
        ], $this->httpRequest());

        $report = app(FieldSalesQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
            now()->toDateString(),
        );

        $row = $report['rows']->firstWhere('employee.id', $this->employee->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['visit_count']);
        $this->assertSame(1, $row['completed_visits']);
        $this->assertSame(1, $row['call_count']);
        $this->assertSame(1, $row['order_count']);
        $this->assertEquals(50.0, $row['revenue']);
        $this->assertStringContainsString('field_visits', $report['method']);
        $this->assertSame(2, $report['totals']['visits']);
        $this->assertSame(1, $report['totals']['orders']);
        $this->assertSame(1, $report['totals']['calls']);
        $this->assertNotNull($order->id);
    }

    public function test_beat_plan_requires_stops_and_orders_them(): void
    {
        $plan = app(CreateBeatPlan::class)->handle([
            'employee_id' => $this->employee->id,
            'name' => 'Morning round',
            'stops' => [
                ['label' => 'Stop A'],
                ['label' => 'Stop B'],
            ],
        ], $this->httpRequest());

        $this->assertSame(2, $plan->stops->count());
        $this->assertSame(1, (int) $plan->stops[0]->sequence_no);
        $this->assertSame(2, (int) $plan->stops[1]->sequence_no);
        $this->assertSame(1, BeatPlan::query()->count());

        try {
            app(CreateBeatPlan::class)->handle([
                'employee_id' => $this->employee->id,
                'name' => 'Empty',
                'stops' => [],
            ], $this->httpRequest());
            $this->fail('Expected stops RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at least one stop', $e->getMessage());
        }
    }

    public function test_territory_create_assigns_reps_and_rejects_duplicate_code(): void
    {
        $territory = app(CreateTerritory::class)->handle([
            'code' => 'dhk-north',
            'name' => 'Dhaka North',
            'employee_ids' => [$this->employee->id],
        ], $this->httpRequest());

        $this->assertSame('DHK-NORTH', $territory->code);
        $this->assertTrue($territory->employees->contains('id', $this->employee->id));
        $this->assertSame(1, Territory::query()->count());

        try {
            app(CreateTerritory::class)->handle([
                'code' => 'dhk-north',
                'name' => 'Dup',
            ], $this->httpRequest());
            $this->fail('Expected duplicate code RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function test_field_routes_enforce_permissions_and_no_fake_rows(): void
    {
        $this->assertSame(0, SalesCallLog::query()->count());
        $this->assertSame(0, FieldVisit::query()->count());
        $this->assertSame(0, BeatPlan::query()->count());
        $this->assertSame(0, Territory::query()->count());

        $user = $this->makeUser();
        $portal = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($portal->id);

        $this->actingAs($user)->get('/app/sales/team/calls')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/field-sales')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/field-visits')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/beat-plans')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/territories')->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.team.view',
            'sales.team.calls',
            'sales.team.field_tracking',
            'sales.team.territories',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/team/calls')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/field-sales')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/field-visits')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/beat-plans')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/territories')->assertOk();

        $this->actingAs($user)->post('/app/sales/team/calls', [
            'employee_id' => $this->employee->id,
            'direction' => 'outbound',
            'outcome' => 'connected',
        ])->assertRedirect();
        $this->assertSame(1, SalesCallLog::query()->count());

        $this->actingAs($user)->post('/app/sales/team/territories', [
            'code' => 'CTG',
            'name' => 'Chittagong',
        ])->assertRedirect();
        $this->assertSame(1, Territory::query()->count());

        // Structure still ships no fake visits/plans until created via HTTP
        $this->assertSame(0, BeatPlan::query()->count());
    }
}

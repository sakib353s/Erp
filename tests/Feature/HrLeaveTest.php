<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Hr\Models\Attendance;
use App\Domain\Hr\Models\LeaveBalance;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Masters\Holiday;
use App\Domain\Masters\LeaveType;
use App\Domain\People\Employee;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 10-19…10-21 gate.
 *
 * What this pins:
 *  · leave days are counted from the calendar minus the weekly off and public
 *    holidays — never typed by the requester;
 *  · pending days immediately reduce availability, so two overlapping requests
 *    cannot both slip through;
 *  · a request beyond the balance is refused with the arithmetic in the message;
 *  · approving moves pending → taken and writes `leave` attendance rows;
 *  · retroactive approval reconciles an already-recorded absence instead of
 *    leaving two contradictory facts in the database;
 *  · cancelling an approved request takes the days back and clears only the
 *    rows the system itself wrote.
 */
class HrLeaveTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin);
    }

    protected function makeEmployee(array $attributes = []): Employee
    {
        static $seq = 0;
        $seq++;

        return Employee::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'LV-'.$seq,
            'first_name' => 'Leave',
            'full_name' => 'Leave Employee '.$seq,
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'status' => 'active',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'weekly_off' => 'friday',
            'annual_leave_days' => 15,
        ], $attributes));
    }

    protected function makeType(array $attributes = []): LeaveType
    {
        return LeaveType::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'annual',
            'name' => 'Annual leave',
            'default_days' => 15,
            'is_paid' => true,
            'is_active' => true,
        ], $attributes));
    }

    public function test_working_days_skip_weekly_off_and_holidays(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();

        Holiday::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Shab-e-Barat',
            'date' => '2026-10-14',
            'type' => 'religious',
            'is_active' => true,
        ]);

        // Mon 12 Oct → Sun 18 Oct 2026: one Friday (16) + one holiday (14) drop out.
        $days = app(LeaveService::class)->workingDaysBetween($employee, '2026-10-12', '2026-10-18');

        $this->assertSame(5.0, $days);

        $request = app(LeaveService::class)->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-18',
            'reason' => 'Family event',
        ], $this->admin->id);

        $this->assertSame('5.00', (string) $request->days);
        $this->assertSame('pending', $request->status);
    }

    public function test_pending_days_reduce_availability_and_the_balance_is_enforced(): void
    {
        $employee = $this->makeEmployee(['annual_leave_days' => 3]);
        $type = $this->makeType(['default_days' => 3]);

        $service = app(LeaveService::class);

        $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-02',
            'to_date' => '2026-11-04', // Mon–Wed, 3 working days
            'reason' => 'Personal',
        ], $this->admin->id);

        $balance = $service->balanceFor($employee, $type, 2026);

        $this->assertSame('3.00', (string) $balance->pending);
        $this->assertSame(0.0, $balance->available(), 'pending already consumed the year');

        $this->actingAs($this->admin)->post('/app/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'reason' => 'One day too many',
        ])->assertSessionHasErrors('leave');

        $this->assertSame(1, LeaveRequest::query()->where('employee_id', $employee->id)->count());
    }

    public function test_overlapping_requests_are_refused(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $service = app(LeaveService::class);

        $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-09',
            'to_date' => '2026-11-11',
            'reason' => 'First request',
        ], $this->admin->id);

        $this->actingAs($this->admin)->post('/app/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-11',
            'to_date' => '2026-11-12',
            'reason' => 'Clashing request',
        ])->assertSessionHasErrors('leave');
    }

    public function test_approval_moves_days_and_writes_leave_attendance(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $service = app(LeaveService::class);

        $request = $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-16',
            'to_date' => '2026-11-18', // Mon–Wed
            'reason' => 'Medical',
        ], $this->admin->id);

        $service->approve($request, $this->admin->id, 'Approved');

        $balance = $service->balanceFor($employee, $type, 2026);

        $this->assertSame('0.00', (string) $balance->pending);
        $this->assertSame('3.00', (string) $balance->taken);

        $rows = Attendance::query()->where('leave_request_id', $request->id)->get();

        $this->assertCount(3, $rows, 'every working day in the range got a leave row');
        $this->assertSame(0, $rows->where('status', '!=', 'leave')->count());
        $this->assertSame(0, $rows->where('source', '!=', 'system')->count());
    }

    public function test_retroactive_approval_reconciles_a_recorded_absence(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();

        // HR recorded the absence first; the request arrives afterwards.
        app(\App\Domain\Hr\Services\AttendanceService::class)->mark($employee, '2026-11-17', [
            'status' => 'absent',
            'reason' => 'Did not report, no call',
        ]);

        $service = app(LeaveService::class);

        $request = $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-17',
            'to_date' => '2026-11-17',
            'reason' => 'Was ill — paperwork came late',
        ], $this->admin->id);

        $service->approve($request, $this->admin->id);

        $row = Attendance::query()->where('employee_id', $employee->id)
            ->whereDate('date', '2026-11-17')->firstOrFail();

        $this->assertSame('leave', $row->status);
        $this->assertSame($request->id, $row->leave_request_id);
    }

    public function test_rejection_releases_pending_days_only(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $service = app(LeaveService::class);

        $request = $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-23',
            'to_date' => '2026-11-24',
            'reason' => 'Personal',
        ], $this->admin->id);

        $service->reject($request, $this->admin->id, 'Coverage not available');

        $balance = $service->balanceFor($employee, $type, 2026);

        $this->assertSame('0.00', (string) $balance->pending);
        $this->assertSame('0.00', (string) $balance->taken);
        $this->assertSame(0, Attendance::query()->where('leave_request_id', $request->id)->count());
    }

    public function test_cancelling_an_approved_request_clears_only_system_rows(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $service = app(LeaveService::class);

        $request = $service->request([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-12-07',
            'to_date' => '2026-12-09',
            'reason' => 'Travel',
        ], $this->admin->id);

        $service->approve($request, $this->admin->id);

        // The employee actually came to work on the middle day — that row is
        // manual and must survive a cancellation.
        $manual = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-12-08')
            ->firstOrFail();

        $manual->forceFill(['source' => 'manual', 'status' => 'present', 'check_in' => '09:00', 'check_out' => '18:00'])->save();

        $service->cancel($request->refresh(), $this->admin->id, 'Returned early');

        $this->assertSame('present', $manual->refresh()->status);
        $this->assertSame('cancelled', $request->refresh()->status);

        $cleared = Attendance::query()->where('leave_request_id', $request->id)->count();
        $this->assertSame(0, $cleared, 'only system-written rows were released');

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->firstOrFail();

        $this->assertSame('0.00', (string) $balance->taken);
    }

    public function test_staff_without_approval_sees_only_their_own_leave(): void
    {
        $mine = $this->makeEmployee(['full_name' => 'Own Record', 'code' => 'LV-MINE']);
        $other = $this->makeEmployee(['full_name' => 'Other Person', 'code' => 'LV-OTHER']);
        $type = $this->makeType();

        $staff = $this->makeUser();
        $staff->roles()->attach($this->roleWith(['portal.erp.access', 'leave.view', 'leave.request'])->id);

        $mine->forceFill(['user_id' => $staff->id])->save();

        $service = app(LeaveService::class);

        $service->request([
            'employee_id' => $other->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-11-02',
            'to_date' => '2026-11-03',
            'reason' => 'Someone else\'s leave',
        ], $this->admin->id);

        $response = $this->actingAs($staff)->get('/app/hr/leave?status=all');

        $response->assertOk();
        $response->assertDontSee('Other Person');

        // …and the staff account cannot file leave for a colleague either.
        $this->actingAs($staff)->post('/app/hr/leave', [
            'employee_id' => $other->id,
            'leave_type_id' => $type->id,
            'from_date' => '2026-12-14',
            'to_date' => '2026-12-15',
            'reason' => 'Trying to file for someone else',
        ])->assertRedirect();

        $this->assertSame(0, LeaveRequest::query()->where('employee_id', $other->id)
            ->whereDate('from_date', '2026-12-14')->count(), 'the request was forced onto the actor, not the colleague');
    }

    public function test_leave_screen_and_balances_render(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->actingAs($this->admin)->get('/app/hr/leave');

        $response->assertOk();
        $response->assertSee($employee->full_name);

        $this->actingAs($this->admin)->get('/app/hr/leave/calendar')->assertOk();
        $this->actingAs($this->admin)->get('/app/hr/leave-types')->assertOk();
        $this->actingAs($this->admin)->get('/app/hr/service-book/'.$employee->id)->assertOk();
    }
}

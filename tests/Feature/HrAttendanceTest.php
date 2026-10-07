<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Hr\Models\Attendance;
use App\Domain\Hr\Models\Department;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Masters\Holiday;
use App\Domain\People\Employee;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 10-10…10-18 gate.
 *
 * What this pins:
 *  · attendance is one row per employee per day — a second mark corrects,
 *    never duplicates;
 *  · a correction of a saved row is refused without a reason (it feeds
 *    payroll deductions), and an unexplained absence is refused too;
 *  · lateness is derived from shift start + grace, not supplied by the caller,
 *    and "present but late" is normalised to `late`;
 *  · public holidays and weekly offs are recorded, so absence means "was due";
 *  · mass-marking a non-working day never overwrites a row already saved;
 *  · the day sheet and month summary are permission-gated and honest.
 */
class HrAttendanceTest extends TestCase
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
            'code' => 'EMP-'.$seq,
            'first_name' => 'Hr',
            'full_name' => 'Hr Employee '.$seq,
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'status' => 'active',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'late_grace_minutes' => 10,
            'weekly_off' => 'friday',
            'annual_leave_days' => 15,
        ], $attributes));
    }

    public function test_attendance_screens_are_permission_gated(): void
    {
        $limited = $this->makeUser();
        $limited->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view'])->id);

        $this->actingAs($limited)->get('/app/hr/attendance')->assertForbidden();
        $this->actingAs($this->admin)->get('/app/hr/attendance')->assertOk();
        $this->actingAs($this->admin)->get('/app/hr/attendance/summary')->assertOk();
    }

    public function test_marking_a_day_twice_corrects_the_same_row(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin)->post('/app/hr/attendance', [
            'employee_id' => $employee->id,
            'date' => '2026-10-05',
            'status' => 'present',
            'check_in' => '09:05',
            'check_out' => '18:00',
        ])->assertRedirect();

        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->id)->count());

        $this->actingAs($this->admin)->post('/app/hr/attendance', [
            'employee_id' => $employee->id,
            'date' => '2026-10-05',
            'status' => 'half_day',
            'check_in' => '09:05',
            'check_out' => '13:00',
            'reason' => 'Left early — approved by floor manager',
        ])->assertRedirect();

        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->id)->count());
        $this->assertSame('half_day', Attendance::query()->where('employee_id', $employee->id)->firstOrFail()->status);
    }

    public function test_correcting_a_saved_row_without_a_reason_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin)->post('/app/hr/attendance', [
            'employee_id' => $employee->id,
            'date' => '2026-10-06',
            'status' => 'present',
        ])->assertRedirect();

        $this->actingAs($this->admin)->post('/app/hr/attendance', [
            'employee_id' => $employee->id,
            'date' => '2026-10-06',
            'status' => 'absent',
        ])->assertSessionHasErrors('attendance');

        $this->assertSame('present', Attendance::query()->where('employee_id', $employee->id)->firstOrFail()->status);
    }

    public function test_lateness_is_derived_from_shift_and_grace(): void
    {
        $employee = $this->makeEmployee();

        $service = app(AttendanceService::class);

        $onTime = $service->mark($employee, '2026-10-07', [
            'status' => 'present', 'check_in' => '09:09', 'check_out' => '18:00',
        ]);
        $this->assertSame(0, $onTime->late_minutes, 'inside the 10-minute grace window');
        $this->assertSame('present', $onTime->status);

        $late = $service->mark($employee, '2026-10-08', [
            'status' => 'present', 'check_in' => '09:25', 'check_out' => '18:00',
        ]);
        $this->assertSame(15, $late->late_minutes, '09:25 against 09:00 + 10 min grace');
        $this->assertSame('late', $late->status, 'present-but-late is normalised');
    }

    public function test_marking_an_absence_without_a_reason_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(RuntimeException::class);

        app(AttendanceService::class)->mark($employee, '2026-10-09', ['status' => 'absent']);
    }

    public function test_holiday_and_weekly_off_are_recorded_without_overwriting(): void
    {
        $employee = $this->makeEmployee();

        Holiday::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Durga Puja',
            'date' => '2026-10-20',
            'type' => 'religious',
            'is_active' => true,
        ]);

        $service = app(AttendanceService::class);

        $this->assertTrue($service->isHoliday('2026-10-20'));

        // Someone who worked the holiday keeps their row.
        $service->mark($employee, '2026-10-20', ['status' => 'present', 'check_in' => '09:00', 'check_out' => '17:00']);

        $recorded = $service->markNonWorkingDay('2026-10-20', 'holiday');

        $this->assertSame(0, $recorded, 'nobody left to mark — the worked row wins');
        $this->assertSame('present', Attendance::query()->where('employee_id', $employee->id)->firstOrFail()->status);

        // A colleague with nothing recorded gets the holiday row.
        $colleague = $this->makeEmployee(['code' => 'EMP-HOL']);
        $this->assertSame(1, $service->markNonWorkingDay('2026-10-20', 'holiday'));
        $this->assertSame('holiday', Attendance::query()->where('employee_id', $colleague->id)->firstOrFail()->status);
    }

    public function test_month_summary_counts_unapplied_absences(): void
    {
        $employee = $this->makeEmployee();
        $service = app(AttendanceService::class);

        $service->mark($employee, '2026-10-01', ['status' => 'present', 'check_in' => '09:00', 'check_out' => '18:00']);
        $service->mark($employee, '2026-10-02', ['status' => 'absent', 'reason' => 'Did not report, no call']);

        $summary = $service->monthSummary($this->defaultBranch()->id, 2026, 10);

        $rows = collect($summary['rows'])->keyBy(fn ($row) => $row['employee']->id);

        $this->assertSame(1, $rows[$employee->id]['present']);
        $this->assertSame(1, $rows[$employee->id]['absent']);
        $this->assertSame(1, $rows[$employee->id]['unapplied_absence'], 'no leave request behind the absence');
        $this->assertSame(1, $summary['totals']['unapplied_absence']);
    }

    public function test_day_sheet_lists_every_active_employee(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->actingAs($this->admin)->get('/app/hr/attendance?date=2026-10-05');

        $response->assertOk();
        $response->assertSee($employee->full_name);

        $sheet = app(AttendanceService::class)->sheet($this->defaultBranch()->id, '2026-10-05');
        $this->assertTrue($sheet->contains(fn ($row) => $row['employee']->id === $employee->id));
        $this->assertNull($sheet->firstWhere(fn ($row) => $row['employee']->id === $employee->id)['attendance']);
    }

    public function test_department_codes_are_unique_inside_the_company(): void
    {
        Department::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SALES',
            'name' => 'Sales',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post('/app/hr/departments', [
            'code' => 'SALES',
            'name' => 'Sales again',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Department::query()->where('code', 'SALES')->count());
    }
}

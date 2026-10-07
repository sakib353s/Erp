<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR / employee domain (spec §10) — structural schema only, no rows.
 *
 * The employees table existed as a minimal master (name, code, free-text
 * designation/department, joining date). Module 10 needs the HR spine:
 *
 *   departments         hierarchy (parent_id) inside the company
 *   designations        job titles, optionally bound to a department
 *   attendances         one row per employee per day — present/absent/leave/
 *                       holiday/half-day/late, with in/out times, source and
 *                       the reason for any manual entry
 *   leave_requests      the request → approval → balance → attendance chain
 *   leave_balances      per employee / type / year, derived from approved
 *                       requests (opening + accrued − taken − pending)
 *
 * Payroll tables (runs, payslips, acknowledgement) are deliberately NOT here —
 * they ship as their own change (§10.3) so the salary formula rules and the
 * acknowledgement state machine get the review they need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('cost_center', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('grade', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('department')
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->after('designation')
                ->constrained('designations')->nullOnDelete();

            // Profile depth (10-02 / 10-03)
            $table->string('employment_type', 24)->default('permanent')->after('employment_status'); // permanent|contract|probation|part_time|daily
            $table->date('confirmation_date')->nullable()->after('joining_date');
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('gender', 16)->nullable()->after('date_of_birth');
            $table->string('blood_group', 8)->nullable()->after('gender');
            $table->string('national_id', 32)->nullable()->after('blood_group');
            $table->text('present_address')->nullable()->after('national_id');
            $table->text('permanent_address')->nullable()->after('present_address');
            $table->string('emergency_contact_name', 128)->nullable()->after('permanent_address');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');

            // Payroll inputs that payroll will read (no salary storage here)
            $table->string('bank_name', 96)->nullable()->after('emergency_contact_phone');
            $table->string('bank_account_no', 48)->nullable()->after('bank_name');
            $table->string('mobile_wallet', 32)->nullable()->after('bank_account_no');

            $table->string('weekly_off', 16)->default('friday')->after('mobile_wallet');
            $table->unsignedSmallInteger('annual_leave_days')->default(0)->after('weekly_off');
            $table->string('shift_start', 5)->default('09:00')->after('annual_leave_days');
            $table->string('shift_end', 5)->default('18:00')->after('shift_start');
            $table->unsignedSmallInteger('late_grace_minutes')->default(10)->after('shift_end');

            $table->index(['company_id', 'department_id']);
            $table->index(['company_id', 'designation_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 16); // present|absent|leave|half_day|holiday|weekend|late
            $table->string('check_in', 5)->nullable();
            $table->string('check_out', 5)->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->boolean('is_overtime')->default(false);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);

            // Provenance: a manual row must say who changed it and why (10-12).
            $table->string('source', 16)->default('manual'); // manual|device|import|system
            $table->string('reason', 500)->nullable();
            $table->foreignId('leave_request_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date', 'status']);
            $table->index(['employee_id', 'date', 'status']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 6, 2);
            $table->boolean('is_half_day')->default(false);
            $table->string('reason', 1000);
            $table->string('status', 24)->default('pending'); // pending|approved|rejected|cancelled
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('attachment_path', 191)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'from_date']);
        });

        // attendances is created before leave_requests (a leave row is written
        // against an existing day), so the reference is closed here.
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->nullOnDelete();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('opening', 6, 2)->default(0);
            $table->decimal('accrued', 6, 2)->default(0);
            $table->decimal('taken', 6, 2)->default(0);
            $table->decimal('pending', 6, 2)->default(0);
            $table->decimal('encashed', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['leave_request_id']);
        });

        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendances');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['designation_id']);
            $table->dropColumn([
                'department_id', 'designation_id', 'employment_type', 'confirmation_date',
                'date_of_birth', 'gender', 'blood_group', 'national_id',
                'present_address', 'permanent_address',
                'emergency_contact_name', 'emergency_contact_phone',
                'bank_name', 'bank_account_no', 'mobile_wallet',
                'weekly_off', 'annual_leave_days', 'shift_start', 'shift_end', 'late_grace_minutes',
            ]);
        });

        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales field slice (02-84…02-87):
 * - sales_call_logs: CRM-style call records per salesperson
 * - field_visits / gps_points: visit log; GPS only with consent
 * - beat_plans / beat_plan_stops: planned customer route for a rep
 * - territories + territory_employees: rep/zone assignment
 *
 * No business rows seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('call_date');
            $table->string('direction', 16)->default('outbound'); // inbound|outbound
            $table->string('outcome', 32)->default('connected');
            $table->string('subject', 200)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'call_date', 'employee_id']);
        });

        Schema::create('field_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('visit_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 16)->default('planned'); // planned|in_progress|completed|cancelled
            $table->string('purpose', 64)->nullable();
            $table->string('location_note', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('gps_consent')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'visit_date', 'employee_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('gps_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('field_visit_id')->nullable()->constrained('field_visits')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'captured_at']);
            $table->index(['company_id', 'field_visit_id']);
        });

        Schema::create('beat_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('name', 160);
            $table->date('plan_date');
            $table->string('status', 16)->default('draft'); // draft|active|completed|cancelled
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'plan_date', 'employee_id']);
        });

        Schema::create('beat_plan_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beat_plan_id')->constrained('beat_plans')->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('territory_id')->nullable();
            $table->string('label', 200)->nullable();
            $table->string('status', 16)->default('pending'); // pending|done|skipped
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['beat_plan_id', 'sequence_no']);
        });

        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('territory_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['territory_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_employees');
        Schema::dropIfExists('territories');
        Schema::dropIfExists('beat_plan_stops');
        Schema::dropIfExists('beat_plans');
        Schema::dropIfExists('gps_points');
        Schema::dropIfExists('field_visits');
        Schema::dropIfExists('sales_call_logs');
    }
};

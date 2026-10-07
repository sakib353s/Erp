<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-93 Own delivery riders: the rider roster (employee-backed
 * profiles) and cash-on-delivery collections recorded against an
 * assignment. rider_assignments itself gains the workflow columns in
 * a follow-up migration (existing name-only rows predate it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('vehicle_type', 32)->nullable();
            $table->string('vehicle_plate', 32)->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            // Privacy gate: rider GPS is stored only while sharing is on.
            $table->boolean('gps_consent')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('rider_cod_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_assignment_id')->constrained('rider_assignments')->cascadeOnDelete();
            $table->foreignId('rider_employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('collected_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'collected_at']);
            $table->index(['company_id', 'rider_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_cod_collections');
        Schema::dropIfExists('rider_profiles');
    }
};

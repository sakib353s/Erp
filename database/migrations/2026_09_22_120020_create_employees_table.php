<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee master (Phase 3 §E). Not every user is an employee; not every
 * employee has a user account. Linkage is optional both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('first_name', 64);
            $table->string('last_name', 64)->nullable();
            $table->string('full_name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('designation', 128)->nullable();
            $table->string('department', 64)->nullable();
            $table->date('joining_date')->nullable();
            $table->string('employment_status', 32)->default('active'); // active|resigned|terminated|on_leave
            $table->boolean('is_technician')->default(false);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('status', 16)->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'employment_status']);
            $table->index(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

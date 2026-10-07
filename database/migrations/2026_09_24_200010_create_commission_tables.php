<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales commission (02-81):
 * - commission_rules: percent/fixed rules per salesperson (config)
 * - commission_calculations: period-scoped earned amounts from real
 *   attributed invoice revenue (accrual GL optional via calculator)
 * - commission_payments: payment records linked to calculations +
 *   journal_entries (approval WF when configured)
 *
 * No fake commission rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name', 120);
            $table->string('rule_type', 32); // percent_of_revenue | fixed_per_period
            $table->decimal('rate', 8, 4)->default(0); // percent when percent_of_revenue
            $table->decimal('fixed_amount', 18, 4)->default(0); // when fixed_per_period
            $table->string('period_type', 16)->default('monthly'); // daily|monthly|yearly
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'employee_id']);
        });

        Schema::create('commission_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('base_amount', 18, 4)->default(0);
            $table->decimal('rate', 8, 4)->default(0);
            $table->decimal('commission_amount', 18, 4)->default(0);
            $table->string('status', 32)->default('pending'); // pending|accrued|pending_approval|paid|void
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'commission_rule_id', 'period_type', 'period_start'], 'commission_calc_unique');
            $table->index(['company_id', 'status', 'period_start']);
        });

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('commission_calculation_id')->constrained('commission_calculations')->restrictOnDelete();
            $table->string('payment_no', 40);
            $table->string('status', 32)->default('pending_approval'); // pending_approval|paid|rejected|void
            $table->string('method', 32)->default('cash'); // cash|bank
            $table->decimal('amount', 18, 4)->default(0);
            $table->date('payment_date');
            $table->string('reference', 100)->nullable();
            $table->string('narration', 500)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'payment_no']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('commission_calculations');
        Schema::dropIfExists('commission_rules');
    }
};

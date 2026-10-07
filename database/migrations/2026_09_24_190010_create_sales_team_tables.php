<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales team (02-78…02-80):
 * - employees.is_salesperson: flag sales-person roster (employee-linked)
 * - sales_targets: daily|monthly|yearly targets per employee
 * - sales_orders/quotations/invoices.sales_person_id: attribution for achievement
 *
 * No fake sales-person or target rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_salesperson')->default(false)->after('is_technician');
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('period_type', 16); // daily|monthly|yearly
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'period_type', 'period_start']);
            $table->index(['company_id', 'period_type', 'period_start']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('sales_person_id')->nullable()->after('customer_id')->constrained('employees')->nullOnDelete();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('sales_person_id')->nullable()->after('customer_id')->constrained('employees')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('sales_person_id')->nullable()->after('customer_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
        });
        Schema::dropIfExists('sales_targets');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_salesperson');
        });
    }
};

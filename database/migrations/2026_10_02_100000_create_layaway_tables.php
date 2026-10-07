<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-41 POS Layaway / Advance Deposit: the balance schedule that sits
 * beside the deposit invoice — one header per layaway sales order plus
 * its dated installment rows (emI-like schedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layaway_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('deposit_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable();
            $table->decimal('order_total', 18, 4);
            $table->decimal('deposit_amount', 18, 4);
            $table->decimal('balance_amount', 18, 4);
            $table->unsignedInteger('installment_count');
            $table->unsignedInteger('interval_days');
            $table->date('first_due_on');
            $table->string('status', 16)->default('open'); // open|completed|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('layaway_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('layaway_schedule_id')->constrained('layaway_schedules')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('due_on');
            $table->decimal('amount', 18, 4);
            $table->string('status', 16)->default('pending'); // pending|paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['layaway_schedule_id', 'line_no']);
            $table->index(['company_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layaway_installments');
        Schema::dropIfExists('layaway_schedules');
    }
};

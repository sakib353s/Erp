<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-39 POS exchange: a counter exchange is one document over two legs —
 * returned goods come back in (exchange_lines direction=return, valued at
 * the original invoice price) and new goods leave (direction=issue, priced
 * server-side onto a fresh POS invoice). price_differential is the signed
 * settlement (exchange_total - return_total): positive = the customer pays
 * the counter, negative = the counter refunds, zero = equal-value swap.
 * The schema is also the groundwork for the generic 07-11 exchange flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete(); // original POS invoice
            $table->foreignId('new_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->string('exchange_no', 32);
            $table->string('status', 24)->default('completed'); // completed|void
            $table->string('payment_method', 16)->default('cash'); // cash|bank|mobile (difference settlement)
            $table->decimal('return_total', 18, 4)->default(0); // returned goods incl. tax
            $table->decimal('exchange_total', 18, 4)->default(0); // new goods incl. tax
            $table->decimal('price_differential', 18, 4)->default(0); // signed: E - R
            $table->string('idempotency_key', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'exchange_no']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
            $table->index(['invoice_id']);
            $table->index(['pos_session_id']);
        });

        Schema::create('exchange_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('exchange_id')->constrained('exchanges')->cascadeOnDelete();
            $table->string('direction', 16)->default('return'); // return|issue
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['exchange_id', 'line_no']);
            $table->index(['invoice_line_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_lines');
        Schema::dropIfExists('exchanges');
    }
};

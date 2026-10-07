<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales core (Phase G, §9.1):
 * - quotations / quotation_lines: price proposal documents (DOC only)
 * - sales_orders / sales_order_lines: commitment docs (reservation only on confirm; no GL)
 * - invoices / invoice_lines: billing (GL on issue per D6/D10; title from document_types)
 * - payments + payment_allocations: receipts (Dr Cash/Bank, Cr AR)
 * - pos_sessions / pos_transactions: counter sales with session float; client_uuid offline idempotency
 * - stock_reservations: order/POS holds against stock_balances.reserved
 *
 * No fake business rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('quote_no', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('revision_of')->nullable()->constrained('quotations')->nullOnDelete();
            $table->string('status', 24)->default('draft'); // draft|sent|viewed|accepted|declined|expired|converted
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 8)->default('BDT');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('shipping', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->string('workflow_state', 24)->default('none');
            $table->string('posting_state', 16)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'quote_no']);
            $table->index(['company_id', 'status', 'quote_date']);
            $table->index(['branch_id', 'quote_date']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['quotation_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('source_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->string('order_no', 32);
            $table->string('status', 24)->default('pending');
            // pending|confirmed|processing|ready_to_ship|picked_up|in_transit|out_for_delivery|
            // delivered|completed|cancelled|return_requested|return_approved|returned|refunded
            $table->string('workflow_state', 24)->default('none');
            $table->date('order_date');
            $table->string('currency', 8)->default('BDT');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('shipping', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->boolean('stock_reserved')->default(false);
            $table->string('cancel_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'order_no']);
            $table->index(['company_id', 'status', 'order_date']);
            $table->index(['branch_id', 'order_date']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('delivered_qty', 18, 4)->default(0);
            $table->decimal('invoiced_qty', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['sales_order_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->foreignId('pos_session_id')->nullable();
            $table->string('invoice_no', 32);
            $table->string('status', 24)->default('draft'); // draft|pending|issued|paid|partial|void
            $table->string('invoice_type', 24)->default('standard'); // standard|proforma|pos
            $table->string('workflow_state', 24)->default('none');
            $table->string('posting_state', 16)->default('draft'); // draft|posted|reversed
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 8)->default('BDT');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('doc_discount', 18, 4)->default(0);
            $table->decimal('taxable_base', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('shipping', 18, 4)->default(0);
            $table->decimal('rounding', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('due_amount', 18, 4)->default(0);
            $table->boolean('tax_applicable')->default(false);
            $table->string('tax_code', 32)->nullable();
            $table->string('printed_title', 64)->nullable();
            $table->string('qr_token_hash', 64)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'status', 'invoice_date']);
            $table->index(['customer_id', 'status']);
            $table->index(['sales_order_id']);
            $table->index(['pos_session_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('sales_order_line_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->boolean('warranty_flag')->default(false);
            $table->timestamps();

            $table->unique(['invoice_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('receipt_no', 32);
            $table->string('direction', 8)->default('in'); // in|out
            $table->string('method', 16)->default('cash'); // cash|bank|cheque|mobile
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('status', 16)->default('posted'); // posted|void
            $table->date('paid_at');
            $table->string('reference', 64)->nullable();
            $table->string('narration', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_no']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'direction', 'paid_at']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('source_type', 32); // sales_order|pos_hold|layaway
            $table->unsignedBigInteger('source_id');
            $table->decimal('qty', 18, 4);
            $table->string('status', 16)->default('active'); // active|released|consumed|expired
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'product_id', 'warehouse_id']);
            $table->index(['company_id', 'product_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_no', 32);
            $table->string('status', 16)->default('open'); // open|closed
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 18, 4)->default(0);
            $table->decimal('closing_counted', 18, 4)->nullable();
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('variance', 18, 4)->default(0);
            $table->decimal('cash_sales', 18, 4)->default(0);
            $table->decimal('non_cash_sales', 18, 4)->default(0);
            $table->decimal('cash_in', 18, 4)->default(0);
            $table->decimal('cash_out', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'session_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('client_uuid', 64)->nullable();
            $table->string('status', 16)->default('completed'); // completed|void|conflict|held
            $table->string('sync_state', 16)->default('synced'); // synced|pending|conflict
            $table->decimal('total', 18, 4)->default(0);
            $table->string('payment_method', 16)->default('cash');
            $table->decimal('tendered', 18, 4)->default(0);
            $table->decimal('change_due', 18, 4)->default(0);
            $table->timestamp('sold_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'client_uuid']);
            $table->index(['pos_session_id', 'sold_at']);
            $table->index(['company_id', 'sync_state']);
        });

        Schema::create('pos_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('hold_no', 32);
            $table->json('lines')->nullable();
            $table->decimal('total', 18, 4)->default(0);
            $table->string('status', 16)->default('held'); // held|resumed|cancelled
            $table->timestamp('held_at');
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'hold_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_holds');
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};

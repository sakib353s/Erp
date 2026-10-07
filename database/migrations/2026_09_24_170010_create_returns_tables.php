<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('return_reason_id')->nullable()->constrained('return_reasons')->nullOnDelete();
            $table->string('return_no', 32);
            $table->string('status', 24)->default('requested'); // requested|approved|denied|received|inspected|credited|refunded|cancelled
            $table->string('source', 16)->default('sales'); // sales|pos|damage
            $table->date('return_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->foreignId('credit_note_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'return_no']);
            $table->index(['company_id', 'status', 'return_date']);
            $table->index(['invoice_id']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->string('disposition', 16)->default('restock'); // restock|repair|scrap
            $table->timestamps();

            $table->unique(['sales_return_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('credit_note_no', 32);
            $table->string('status', 16)->default('draft'); // draft|issued|void
            $table->string('posting_state', 16)->default('draft'); // draft|posted|reversed
            $table->date('note_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->string('printed_title', 64)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'credit_note_no']);
            $table->index(['company_id', 'status', 'note_date']);
            $table->index(['invoice_id']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['credit_note_id', 'line_no']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->string('refund_no', 32);
            $table->string('status', 16)->default('posted'); // posted|void
            $table->string('method', 16)->default('cash'); // cash|bank|mobile|adjustment
            $table->decimal('amount', 18, 4)->default(0);
            $table->date('refund_date');
            $table->string('reference', 64)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'refund_no']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'refund_date']);
        });

        // Link return to credit note both ways once CN exists
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreign('credit_note_id')->references('id')->on('credit_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};

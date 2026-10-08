<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16-16 / §16-17 / §16-18 — warranties, their states, and what was claimed.
 *
 * Three tables, because there are three different facts:
 *
 *  · `product_warranties` — the **promise**: how long this product is covered
 *    for, what the cover includes, and on whose behalf. A product with no row
 *    here is a product nobody promised anything about, which is why the desk
 *    says "no policy" rather than showing a default of twelve months.
 *  · `warranties` — one row per delivered line: the promise **applied to a
 *    delivery**, with the dates fixed at the moment the goods reached the
 *    customer and never recomputed afterwards. The unique key on the source
 *    line is what makes "activated exactly once" structural rather than
 *    hopeful — a second delivery event cannot write a second warranty.
 *  · `warranty_claims` — what the customer said afterwards, and what the
 *    company did about it. A claim never edits the warranty: the cover is a
 *    fact about a date, and a claim is an event on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('months')->default(12);
            $table->string('kind', 16)->default('manufacturer'); // manufacturer|seller|service
            $table->boolean('covers_parts')->default(true);
            $table->boolean('covers_labour')->default(true);
            $table->string('terms', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'product_warranties_company_product_unique');
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 32);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('challan_id')->nullable()->constrained('delivery_challans')->nullOnDelete();

            // The line the cover came from, and the key that makes activation
            // exactly-once: one warranty per delivered line, ever.
            $table->string('source', 16); // challan|invoice|manual
            $table->unsignedBigInteger('source_line_id');
            $table->string('source_line_key', 64);

            $table->string('serial_no', 64)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->unsignedSmallInteger('months');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('active'); // active|claimed|expired|voided
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'warranties_company_code_unique');
            $table->unique(['company_id', 'source_line_key'], 'warranties_source_line_unique');
            $table->index(['company_id', 'ends_on'], 'warranties_company_ends_index');
            $table->index(['company_id', 'status'], 'warranties_company_status_index');
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 32);
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->date('reported_on');
            $table->string('fault', 500);
            $table->string('status', 16)->default('open'); // open|processing|approved|rejected|completed
            $table->string('resolution', 16)->nullable();  // repair|replace|refund|reject
            $table->date('resolved_on')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_notes', 500)->nullable();
            $table->decimal('cost', 18, 4)->default(0);
            $table->foreignId('service_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'warranty_claims_company_code_unique');
            $table->index(['company_id', 'status'], 'warranty_claims_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranties');
        Schema::dropIfExists('product_warranties');
    }
};

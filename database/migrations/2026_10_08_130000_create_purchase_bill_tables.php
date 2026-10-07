<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase bills (§03.6) — the point where a delivery becomes a liability.
 *
 * Phase order matters: `purchase_orders` / `goods_receipts` already exist
 * (2026_10_08_120000), so a bill may point at the order it was raised from and
 * at the receipt it matches against. Both links are nullable on purpose:
 * a service supplier invoices without any goods ever arriving, and the system
 * records that honestly instead of forcing a fake GRN.
 *
 * `match_state` / `match_summary` hold the three-way match result (PO ↔ GRN ↔
 * bill). Tolerances are not configurable yet, so a mismatch is *recorded* — it
 * never silently disappears and it is shown on the bill — but blocking stays a
 * deliberate human act (posting needs its own permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();

            $table->string('code', 32);                            // BILL-00001, per company
            $table->string('supplier_bill_no', 64)->nullable();    // the number on the supplier's paper
            $table->date('bill_date');
            $table->date('due_date')->nullable();                  // supplier terms + bill_date

            $table->string('status', 24)->default('draft');        // draft|pending_approval|approved|partially_paid|paid|cancelled
            $table->string('posting_state', 16)->default('draft'); // draft|posted
            $table->string('match_state', 24)->nullable();         // matched|qty_mismatch|price_mismatch|unmatched|not_applicable
            $table->string('match_summary', 500)->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('due_amount', 18, 4)->default(0);

            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'bill_date']);
            $table->index(['supplier_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::create('purchase_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();

            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax_rate', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_lines');
        Schema::dropIfExists('purchase_bills');
    }
};

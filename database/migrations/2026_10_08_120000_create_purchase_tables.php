<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase domain (spec §03 + §06) — suppliers are parties, the rest is the
 * buy-side document chain:
 *
 *   purchase_orders → goods_receipts → (next change) purchase_bills → payments
 *
 * Design decisions that matter later:
 *   · `suppliers` existed as a thin party record so bills could reference a
 *     name; it is deepened here (contact, terms, TIN/BIN, bank, category) — no
 *     new table, no duplicate party master.
 *   · Receipt quantities live on the LINE (`qty_received`) so a partial receipt
 *     is arithmetic, not a status guess. Goods receipt posting is the only
 *     thing that may move stock in, and it records GRN id as the movement
 *     source so the ledger can be traced back to the paper.
 *   · Money fields are decimal(18,4) like every other document in the system;
 *     nothing here stores a derived total that the lines cannot reproduce.
 *   · Purchase bills are deliberately NOT in this migration: they need the
 *     supplier ledger + VAT/Mushak treatment, which lands as its own change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('contact_person', 128)->nullable()->after('name');
            $table->string('phone_alt', 32)->nullable()->after('phone');
            $table->string('category', 32)->nullable()->after('email'); // goods|service|transport|utility
            $table->string('tin', 32)->nullable()->after('bin');
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('tin');
            $table->string('bank_name', 96)->nullable()->after('payment_terms_days');
            $table->string('bank_account_no', 48)->nullable()->after('bank_name');
            $table->string('mobile_wallet', 32)->nullable()->after('bank_account_no');
            $table->decimal('credit_limit', 18, 4)->default(0)->after('mobile_wallet');
            $table->text('notes')->nullable()->after('credit_limit');
            $table->boolean('is_blacklisted')->default(false)->after('notes');
            $table->string('blacklist_reason', 500)->nullable()->after('is_blacklisted');
            $table->timestamp('blacklisted_at')->nullable()->after('blacklist_reason');
            $table->foreignId('blacklisted_by')->nullable()->after('blacklisted_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('blacklisted_by')
                ->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'is_blacklisted']);
            $table->index(['company_id', 'category']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('reference', 64)->nullable();          // supplier's own quotation number
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            // draft → pending_approval → approved → partially_received → received | cancelled
            $table->string('status', 24)->default('draft');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->string('payment_terms', 64)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'order_date']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('description', 191);
            $table->decimal('qty_ordered', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['purchase_order_id', 'sort_order']);
            $table->index('product_id');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('code', 32);
            $table->string('challan_no', 64)->nullable();          // supplier's delivery challan
            $table->date('received_date');
            $table->string('status', 24)->default('draft');        // draft|posted|cancelled
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'received_date']);
            $table->index(['supplier_id', 'received_date']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_received', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->string('batch_no', 64)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['goods_receipt_id', 'sort_order']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign(['blacklisted_by']);
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'contact_person', 'phone_alt', 'category', 'tin', 'payment_terms_days',
                'bank_name', 'bank_account_no', 'mobile_wallet', 'credit_limit', 'notes',
                'is_blacklisted', 'blacklist_reason', 'blacklisted_at', 'blacklisted_by', 'created_by',
            ]);
        });
    }
};

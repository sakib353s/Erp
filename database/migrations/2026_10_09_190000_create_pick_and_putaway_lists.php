<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-44 — pick lists and putaway lists: the two directions goods move *inside*
 * a warehouse.
 *
 * Neither table carries a balance, and that is deliberate. A bin is a place
 * (§04-42/43), so a pick list says *where to walk and how many to take* while
 * the quantity keeps living in one ledger per product per warehouse. The moment
 * these tables stored quantities of their own, there would be two truths about
 * the same shelf.
 *
 * A pick list hangs off the order it serves when it has one (a manual list is
 * just as real — a shop picks for a walk-in counter order it never entered as an
 * order). A putaway list hangs off the goods receipt it unloads: paper arriving
 * comes in through a receipt, so the only honest source of a putaway task is the
 * document that brought the stock in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // The order this list serves, when there is one. Null = a manual list.
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->string('code', 64);
            $table->string('status', 24)->default('draft'); // draft|assigned|picking|picked|cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'warehouse_id', 'status']);
            $table->index(['sales_order_id']);
        });

        Schema::create('pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_list_id')->constrained('pick_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Where to walk. Null means nobody has told the system where this
            // product lives yet — the picker is asked to say, not guessed at.
            $table->foreignId('warehouse_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            // The batch the shelf should give up first, when the product is
            // batch-tracked and the warehouse holds one (§04-37…41, FEFO).
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('picked_quantity', 18, 4)->default(0);
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['pick_list_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('putaway_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->string('code', 64);
            $table->string('status', 24)->default('draft'); // draft|assigned|putting_away|put_away|cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'warehouse_id', 'status']);
            $table->index(['goods_receipt_id']);
        });

        Schema::create('putaway_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('putaway_list_id')->constrained('putaway_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Where the goods are meant to go (the product's pick face when it has
            // one) — and, once placed, where they actually went.
            $table->foreignId('warehouse_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            // Where they actually went. The column above is the plan; this one is
            // the fact, and the two are allowed to differ — a plan that could not
            // be followed is information, not an error to be hidden.
            $table->foreignId('placed_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('placed_quantity', 18, 4)->default(0);
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['putaway_list_id', 'line_no']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_list_lines');
        Schema::dropIfExists('putaway_lists');
        Schema::dropIfExists('pick_list_lines');
        Schema::dropIfExists('pick_lists');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-55/04-56/04-58 — the reorder suggestion register.
 *
 * A suggestion is a *recorded decision*: the figures that were looked at (the
 * window, the average day's demand, what the ledger says is there, what is
 * already coming), the trigger it fell through, what somebody proposed, and
 * what a person did about it — accepted into a purchase order, dismissed with
 * a reason, or superseded when a later look said something else.
 *
 * Nothing here writes stock or money: accepting drafts a purchase order through
 * the purchase module, and that module's own approval ladder still decides
 * whether the order is ever sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorder_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('reorder_policies')->nullOnDelete();
            $table->string('code', 64);
            $table->string('status', 24)->default('suggested'); // suggested|accepted|dismissed|superseded
            $table->date('run_date');
            $table->unsignedSmallInteger('demand_window_days')->default(30);
            // What the formula saw, stored as it was seen — a suggestion read
            // next month must still explain the number it printed that day.
            $table->decimal('avg_daily_demand', 18, 4)->default(0);
            $table->decimal('on_hand', 18, 4)->default(0);
            $table->decimal('reserved', 18, 4)->default(0);
            $table->decimal('available', 18, 4)->default(0);
            $table->decimal('in_transit', 18, 4)->default(0);
            $table->decimal('trigger_qty', 18, 4)->default(0);   // why it was flagged at all
            $table->decimal('shortage', 18, 4)->default(0);      // how far below that trigger
            $table->decimal('suggested_qty', 18, 4)->default(0);
            $table->decimal('final_qty', 18, 4)->nullable();     // when a person changed the number
            $table->json('inputs')->nullable();                  // the ladder behind the quantity
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'run_date']);
            $table->index(['company_id', 'product_id', 'warehouse_id']);
            $table->index(['company_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_suggestions');
    }
};

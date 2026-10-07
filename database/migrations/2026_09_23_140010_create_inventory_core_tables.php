<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory core (Phase E, §8):
 * - products: stock-managed catalogue (SKU unique, valuation method)
 * - stock_movements: IMMUTABLE source of truth (append-only ledger)
 * - stock_balances: derived cache (rebuildable from movements)
 * - stock_layers: FIFO/LIFO/WAC valuation layers
 * - stock_adjustments / stock_transfers: operational documents
 * - reorder_policies: min/max thresholds feeding stock alerts
 *
 * No fake business rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('code', 32);
            $table->string('sku', 64);
            $table->string('name', 191);
            $table->string('barcode', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('cost_method', 16)->default('wac'); // fifo|lifo|wac|standard
            $table->decimal('standard_cost', 18, 4)->default(0);
            $table->boolean('is_stocked')->default(true);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'barcode']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('movement_type', 32); // OPENING|ADJUST_IN|ADJUST_OUT|TRANSIT_OUT|TRANSIT_IN|DAMAGE_OUT|WRITE_OFF|…
            $table->string('state', 24)->default('on_hand'); // on_hand|reserved|in_transit|damaged|quarantined
            $table->decimal('qty_signed', 18, 4); // + inbound / − outbound relative to warehouse on-hand intent
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('valuation_method', 16)->nullable();
            $table->unsignedBigInteger('layer_id')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 64)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('narration', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'product_id', 'occurred_at']);
            $table->index(['warehouse_id', 'product_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['company_id', 'movement_type', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('on_hand', 18, 4)->default(0);
            $table->decimal('reserved', 18, 4)->default(0);
            $table->decimal('in_transit', 18, 4)->default(0);
            $table->decimal('damaged', 18, 4)->default(0);
            $table->decimal('quarantined', 18, 4)->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id'], 'stock_balances_uniq_wh_product');
            $table->index(['company_id', 'product_id']);
            $table->index(['branch_id', 'product_id']);
        });

        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_initial', 18, 4);
            $table->decimal('qty_remaining', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamp('received_at');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'received_at']);
            $table->index(['company_id', 'product_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('adjustment_no', 64);
            $table->date('adjustment_date');
            $table->string('reason', 500);
            $table->string('status', 16)->default('posted'); // draft|posted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'adjustment_no']);
            $table->index(['company_id', 'status', 'adjustment_date']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_delta', 18, 4); // + / −
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['stock_adjustment_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('transfer_no', 64);
            $table->date('transfer_date');
            $table->string('status', 24)->default('draft'); // draft|dispatched|received|discrepancy|cancelled
            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'status']);
            $table->index(['from_warehouse_id']);
            $table->index(['to_warehouse_id']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_sent', 18, 4)->default(0);
            $table->decimal('qty_received', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['stock_transfer_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('reorder_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('min_level', 18, 4)->default(0);
            $table->decimal('max_level', 18, 4)->default(0);
            $table->decimal('reorder_point', 18, 4)->default(0);
            $table->decimal('safety_stock', 18, 4)->default(0);
            $table->decimal('reorder_qty', 18, 4)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'warehouse_id'], 'reorder_policies_uniq');
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_policies');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_layers');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('products');
    }
};

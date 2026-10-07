<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-98 Packaging management: packaging types map to stock-managed
 * products; consumption records (packaging_usage) post PACK_CONSUME
 * movements through StockLedgerService and carry the layer-valued cost
 * onto the order (sales_orders.packaging_cost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('packaging_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('packaging_type_id')->constrained('packaging_types')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->timestamp('consumed_at');
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sales_order_id']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('packaging_cost', 18, 4)
                ->default(0)
                ->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('packaging_cost');
        });

        Schema::dropIfExists('packaging_usage');
        Schema::dropIfExists('packaging_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('challan_no', 32);
            $table->string('status', 16)->default('draft'); // draft|ready|dispatched|delivered|cancelled
            $table->string('printed_title', 64)->nullable();
            $table->date('challan_date');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('courier_name', 100)->nullable();
            $table->string('tracking_no', 64)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'challan_no']);
            $table->index(['company_id', 'status', 'challan_date']);
            $table->index(['sales_order_id']);
        });

        Schema::create('delivery_challan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_challan_id')->constrained('delivery_challans')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['delivery_challan_id', 'line_no']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_challan_lines');
        Schema::dropIfExists('delivery_challans');
    }
};

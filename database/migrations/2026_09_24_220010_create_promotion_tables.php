<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions (02-105…02-107) + Buy X Get Y coupon columns (02-102 tail).
 * - promotions / promotion_items: windowed, branch-scoped, priority-ranked
 * - promotion_usages: real attribution onto source documents
 * - coupons.buy_qty / get_qty: required for type buy_x_get_y
 *
 * No fake promotion rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 48)->nullable();
            $table->string('type', 24); // percent_off|fixed_off
            $table->string('kind', 24)->default('standard'); // standard|seasonal|flash
            $table->decimal('value', 18, 4)->default(0);
            $table->decimal('min_subtotal', 18, 4)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'kind']);
            $table->index(['company_id', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('min_qty')->default(1);
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->string('source_type', 32); // quotation|sales_order|invoice|pos_transaction
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->unique(['promotion_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'used_at']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('buy_qty')->nullable()->after('value');
            $table->unsignedInteger('get_qty')->nullable()->after('buy_qty');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['buy_qty', 'get_qty']);
        });
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (02-101…02-104):
 * - coupons: percent_off | fixed_off | free_shipping with window/limits
 * - coupon_usages: redemptions linked to source documents
 * - quotation/sales_order/invoice coupon columns for server-side application
 *
 * No fake coupon rows are seeded by structure alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 48);
            $table->string('type', 24); // percent_off|fixed_off|free_shipping
            $table->decimal('value', 18, 4)->default(0); // percent or fixed amount
            $table->decimal('min_subtotal', 18, 4)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->string('source_type', 32); // quotation|sales_order|invoice|pos_transaction
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->unique(['coupon_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'used_at']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('coupon_code', 48)->nullable()->after('discount');
            $table->decimal('coupon_discount', 18, 4)->default(0)->after('coupon_code');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('coupon_code', 48)->nullable()->after('discount');
            $table->decimal('coupon_discount', 18, 4)->default(0)->after('coupon_code');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('coupon_code', 48)->nullable()->after('doc_discount');
            $table->decimal('coupon_discount', 18, 4)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount']);
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount']);
        });
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
    }
};

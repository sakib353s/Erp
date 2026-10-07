<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing rules engine (02-111): customer groups + effective-dated,
 * priority-ordered rules for special / customer-group / quantity-break /
 * geographic / time-based pricing. customers gains a nullable group link
 * (plain column — districts already carry no FK either; the relation is
 * enforced in the application layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('rule_type', 24); // special|customer_group|quantity_break|geographic|time_based
            $table->string('name', 120);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);

            // Optional scopes — null means "applies to everything".
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('customer_group_id')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->unsignedInteger('qty_min')->nullable();
            $table->unsignedInteger('qty_max')->nullable();

            // Value: exactly one of an absolute price or a percent discount.
            $table->decimal('price', 18, 4)->nullable();
            $table->decimal('percent_off', 9, 4)->nullable();

            // Effective dating: date window + time-of-day window.
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'rule_type']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_group_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('customer_group_id');
        });

        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('customer_groups');
    }
};

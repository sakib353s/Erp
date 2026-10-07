<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM / customer domain (spec §05) — structural schema only, no rows.
 *
 * The customers table existed as a minimal master (code/name/phone/geo) so
 * that sales documents could reference a party. Module 05 needs the full
 * relationship model around it:
 *
 *   customer_addresses     delivery/billing addresses (BD district + upazila FK)
 *   customer_contacts      named contact persons per customer
 *   customer_credit_history  every credit-limit decision, append-only
 *   customer_feedback      NPS / service feedback
 *   customer_referrals     referrer → referred outcome (incentive input)
 *   customer_wishlists     product watch-list sold into by marketing
 *
 * Customer balance truth stays where §09 puts it: posted journal_lines +
 * payment_allocations. Nothing here stores a maintained "due" column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Profile depth (05-02, 05-05)
            $table->string('type', 16)->default('individual')->after('name'); // individual|business
            $table->string('contact_person', 128)->nullable()->after('bin');
            $table->string('alt_phone', 32)->nullable()->after('phone');
            $table->string('tax_vat_no', 32)->nullable()->after('bin');
            $table->unsignedSmallInteger('credit_days')->default(0)->after('credit_limit');
            $table->decimal('opening_balance', 18, 4)->default(0)->after('credit_days');
            $table->string('opening_balance_type', 8)->default('due')->after('opening_balance'); // due|advance
            $table->text('notes')->nullable()->after('address_line1');

            // Blacklist (05-21)
            $table->boolean('is_blacklisted')->default(false)->after('is_active');
            $table->string('blacklist_reason', 500)->nullable()->after('is_blacklisted');
            $table->foreignId('blacklisted_by')->nullable()->after('blacklist_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('blacklisted_at')->nullable()->after('blacklisted_by');

            // Loyalty / segmentation inputs
            $table->unsignedInteger('loyalty_points')->default(0)->after('blacklisted_at');
            $table->string('segment', 32)->nullable()->after('loyalty_points'); // vip|regular|new|at_risk

            $table->index(['company_id', 'is_blacklisted']);
            $table->index('phone');
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 64)->default('Delivery'); // Delivery|Billing|Warehouse|Other
            $table->string('contact_name', 128)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('address_line1', 191);
            $table->string('address_line2', 191)->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('upazila_id')->nullable();
            $table->string('postcode', 12)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
            $table->index('district_id');
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('designation', 96)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_primary']);
        });

        Schema::create('customer_credit_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('old_limit', 18, 2)->default(0);
            $table->decimal('new_limit', 18, 2)->default(0);
            $table->unsignedSmallInteger('old_credit_days')->default(0);
            $table->unsignedSmallInteger('new_credit_days')->default(0);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('customer_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 0-10 NPS
            $table->string('channel', 24)->default('phone'); // phone|sms|email|in_person|portal
            $table->string('comment', 1000)->nullable();
            $table->string('category', 32)->nullable(); // product|delivery|service|pricing|other
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['company_id', 'score']);
        });

        Schema::create('customer_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('referred_name', 191);
            $table->string('referred_phone', 32)->nullable();
            $table->foreignId('referred_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status', 24)->default('new'); // new|contacted|converted|declined
            $table->decimal('reward_amount', 18, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('customer_wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_wishlists');
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_feedback');
        Schema::dropIfExists('customer_credit_history');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customer_addresses');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['blacklisted_by']);
            $table->dropColumn([
                'type', 'contact_person', 'alt_phone', 'tax_vat_no', 'credit_days',
                'opening_balance', 'opening_balance_type', 'notes',
                'is_blacklisted', 'blacklist_reason', 'blacklisted_by', 'blacklisted_at',
                'loyalty_points', 'segment',
            ]);
        });
    }
};

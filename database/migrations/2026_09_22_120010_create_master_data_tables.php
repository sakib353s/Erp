<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 master data (Spec §14): units, categories, brands, expense
 * categories, payment methods, delivery zones, BD geo (district/upazila),
 * holidays, banks, couriers, SMS providers, leave types, return/cancel
 * reasons, tax rates. Structural reference data only — never fake
 * business transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('symbol', 16)->nullable();
            $table->string('base_unit', 32)->nullable();
            $table->decimal('conversion_factor', 18, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->boolean('is_global')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            $table->string('provider', 32)->nullable();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 64);
            $table->string('name_bn', 64)->nullable();
            $table->string('division', 32)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('upazilas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('name', 64);
            $table->string('name_bn', 64)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['district_id', 'code']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->decimal('base_charge', 18, 2)->default(0);
            $table->decimal('per_kg_charge', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('delivery_zone_district', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained('delivery_zones')->cascadeOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['delivery_zone_id', 'district_id']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 128);
            $table->string('name_bn', 128)->nullable();
            $table->date('date');
            $table->string('type', 32)->default('public'); // public|bank|optional
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'date']);
            $table->index(['date']);
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('swift_code', 16)->nullable();
            $table->string('routing_number', 16)->nullable();
            $table->string('website', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            $table->string('service_status', 32)->default('available');
            $table->string('configuration_status', 32)->default('not_configured');
            $table->string('credentials_meta', 500)->nullable(); // never secrets
            $table->boolean('integration_enabled')->default(false);
            $table->string('tracking_url_pattern', 255)->nullable();
            $table->boolean('branch_specific')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('sms_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('api_endpoint', 255)->nullable();
            $table->string('sender_id', 32)->nullable();
            $table->string('config_status', 32)->default('not_configured');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->unsignedSmallInteger('default_days')->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('return_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->boolean('requires_inspection')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('cancel_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('tax_type', 32)->default('vat'); // vat|sd|tt|other
            $table->decimal('rate', 9, 6); // e.g. 15.000000
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->json('applicability')->nullable();
            $table->json('document_applicability')->nullable();
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->string('source_note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('cancel_reasons');
        Schema::dropIfExists('return_reasons');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('sms_providers');
        Schema::dropIfExists('couriers');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('delivery_zone_district');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('upazilas');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('units');
    }
};

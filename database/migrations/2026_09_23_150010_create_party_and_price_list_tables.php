<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F party + price-list masters (spec §14 / architecture phase F):
 * customers, suppliers, price lists. Structural schema only — no rows
 * are seeded (spec §0.3 forbids fake business data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 191);
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('bin', 32)->nullable(); // Bangladeshi BIN/TIN
            $table->unsignedBigInteger('district_id')->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 191);
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('bin', 32)->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 18, 4);
            $table->timestamps();
            $table->unique(['price_list_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};

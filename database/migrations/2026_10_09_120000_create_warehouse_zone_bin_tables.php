<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-42/04-43 — the warehouse is a place with a structure.
 *
 * A warehouse row alone cannot answer "where is it?", so the physical layout is
 * modelled: ZONES inside a warehouse (receiving, storage, picking, dispatch…),
 * BINS inside a zone, and the assignment that says which bin a product is
 * picked from.
 *
 * Deliberately *not* modelled here: stock per bin. The ledger counts stock per
 * product per warehouse, and inventing a second place where quantities live
 * would create two truths to reconcile. A bin is where people are told to go,
 * not a second balance sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 191);
            // What the zone is for — the list a putaway or pick task reads.
            $table->string('type', 24)->default('storage'); // receiving|storage|picking|packing|dispatch|returns
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('warehouse_zone_id')->constrained('warehouse_zones')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 191)->nullable();
            // A bin that people cannot pick from (a bulk pallet position, a
            // quarantine corner) is still a bin — it just is not offered to a
            // picker.
            $table->boolean('is_pickable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_zone_id', 'code']);
            $table->index(['company_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'is_pickable']);
        });

        Schema::create('product_bin_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_bin_id')->constrained('warehouse_bins')->cascadeOnDelete();
            // The bin a picker is sent to first for this product. One primary
            // per product per warehouse is enforced in the service, because a
            // partial unique index is not portable across the databases this
            // app runs on.
            $table->boolean('is_primary')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_bin_id']);
            $table->index(['warehouse_bin_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bin_assignments');
        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_zones');
    }
};

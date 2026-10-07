<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-38/04-39 — a batch becomes a row somebody can point at. (The serial half
 * of §04-37 is not built yet and deliberately claims no table: a register nobody
 * can write to would be scaffolding, not stock control.)
 *
 * Until now a batch was a string on a receipt line and an expiry date was
 * nowhere: "expiring stock" could not be asked as a question, only guessed from
 * narration. The register below makes a batch a real thing — product, warehouse,
 * number, dates, and the layer that physically holds it — so expiry becomes a
 * query over rows rather than a report somebody maintains by hand.
 *
 * Two deliberate choices:
 *
 *  · the batch hangs off the *layer*, not off a parallel quantity column. The
 *    layer is what the ledger consumes, so "how much of this batch is left" is
 *    the same number that valuation already keeps — no second quantity to
 *    reconcile, exactly like the bin rule in §04-43;
 *  · a date change is recorded in its own trail table. Correcting an expiry is a
 *    legitimate thing to have to do and a thing somebody should be able to
 *    explain afterwards, so the change is a row with a reason, not an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('batch_no', 64);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'warehouse_id', 'product_id', 'batch_no'], 'stock_batches_ref_unique');
            $table->index(['company_id', 'expires_on']);
            $table->index(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_batch_expiry_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();
            $table->date('expires_on_before')->nullable();
            $table->date('expires_on_after')->nullable();
            $table->string('reason', 500);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_batch_id', 'created_at']);
        });

        Schema::table('stock_layers', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('product_id')->constrained('stock_batches')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('layer_id')->constrained('stock_batches')->nullOnDelete();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->date('manufactured_on')->nullable()->after('batch_no');
            $table->date('expires_on')->nullable()->after('manufactured_on');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['manufactured_on', 'expires_on']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });

        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });

        Schema::dropIfExists('stock_batch_expiry_changes');
        Schema::dropIfExists('stock_batches');
    }
};

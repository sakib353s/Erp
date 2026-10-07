<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk price update batches (02-109) + append-only price history
 * (02-110 reads it). A batch carries the payload and the honest lifecycle
 * queued → pending_approval → applied | failed; every applied row writes
 * one history line linked to the batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_bulk_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->string('change_type', 16); // percent|set
            $table->decimal('percent', 9, 4)->nullable();
            $table->decimal('set_price', 18, 4)->nullable();
            $table->json('payload');
            $table->string('note')->nullable();
            $table->string('status', 24)->default('queued'); // queued|pending_approval|applied|failed
            $table->decimal('threshold_pct', 9, 4)->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('price_list_item_id')->nullable();
            $table->decimal('old_price', 18, 4);
            $table->decimal('new_price', 18, 4);
            $table->decimal('percent_change', 9, 4)->nullable();
            $table->string('source', 24)->default('manual'); // manual|bulk_update
            $table->foreignId('price_bulk_update_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('price_bulk_updates');
    }
};

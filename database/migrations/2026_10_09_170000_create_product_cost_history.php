<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-10 — the record of what a product's cost *said*, as opposed to what the
 * stock actually cost.
 *
 * Two different questions, two different sources: the valuation layers
 * (`stock_layers`) answer "what did the goods cost when they arrived", and this
 * table answers "who changed the standard cost on the product record, when, from
 * what to what, and why". Keeping the second one is what makes a cost jump
 * explainable instead of mysterious — and the screen shows both side by side so
 * nobody has to guess which one moved.
 *
 * Append-only: a row is never updated or deleted, and nothing but a cost-field
 * change writes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cost_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('old_standard_cost', 18, 4)->nullable();
            $table->decimal('new_standard_cost', 18, 4)->nullable();
            $table->string('old_cost_method', 16)->nullable();
            $table->string('new_cost_method', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('changed_at');
            $table->index(['company_id', 'product_id', 'changed_at'], 'product_cost_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_history');
    }
};

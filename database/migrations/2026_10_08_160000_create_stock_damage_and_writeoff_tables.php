<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-46…04-49 — damage and loss, and the write-off that removes value.
 *
 * Two documents, because they are two different statements:
 *   · a damage/loss ENTRY states a fact about goods we hold (broken, missing);
 *   · a WRITE-OFF is the deliberate decision that value leaves the company,
 *     and it is the document that takes a second pair of eyes.
 *
 * Entries carry their own value at the moment of recording (taken from the
 * valuation layers, never estimated); the write-off stores the cost the ledger
 * actually consumed when it posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_damage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('kind', 16); // damage|loss
            $table->date('entry_date');
            $table->string('reason_code', 32)->nullable();
            $table->string('reason', 500);
            $table->string('status', 24)->default('recorded'); // recorded|released
            $table->decimal('total_value', 18, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'kind', 'entry_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_damage_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_damage_entry_id')->constrained('stock_damage_entries')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['stock_damage_entry_id', 'line_no']);
            $table->index(['product_id']);
        });

        Schema::create('stock_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 64);
            $table->date('writeoff_date');
            // Which compartment the goods are taken from. `damaged` and
            // `quarantined` are compartments outside sellable stock; writing off
            // from `on_hand` is the direct route (expired goods never flagged).
            $table->string('source_state', 16)->default('damaged');
            $table->string('reason', 500);
            $table->string('status', 32)->default('pending_approval'); // pending_approval|approved|rejected|cancelled
            $table->decimal('total_value', 18, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'writeoff_date']);
            $table->index(['company_id', 'warehouse_id']);
        });

        Schema::create('stock_writeoff_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_writeoff_id')->constrained('stock_writeoffs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['stock_writeoff_id', 'line_no']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_writeoff_lines');
        Schema::dropIfExists('stock_writeoffs');
        Schema::dropIfExists('stock_damage_entry_lines');
        Schema::dropIfExists('stock_damage_entries');
    }
};

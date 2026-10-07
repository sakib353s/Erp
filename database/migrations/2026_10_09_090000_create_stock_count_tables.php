<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-31 — stock count / cycle count.
 *
 * A count is a sheet opened against one warehouse, in one of two scopes:
 *
 *   · `full`  — every stocked product in that warehouse, opened at once;
 *   · `cycle` — the products somebody chose (a bay, an aisle, one line).
 *
 * Each line carries the balance the ledger showed when the sheet was opened
 * (`system_qty`), what the counter found (`counted_qty`, null until they say),
 * and — once the sheet is posted — the delta the ledger actually applied
 * (`posted_delta`). The three numbers are stored separately on purpose: the
 * snapshot is what the counter was asked about, the posted delta is what the
 * stock ended up at, and a count whose two deltas disagree is a count taken
 * while the warehouse kept moving, which is worth being able to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('scope', 16)->default('full'); // full|cycle
            $table->date('count_date');
            $table->string('status', 24)->default('counting'); // counting|posted|cancelled
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('counted_lines')->default(0);
            $table->unsignedInteger('variance_lines')->default(0);
            $table->decimal('variance_value', 18, 4)->default(0);
            $table->foreignId('stock_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('cancel_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'count_date']);
            $table->index(['company_id', 'warehouse_id']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('system_qty', 18, 4)->default(0);
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->default(0);
            $table->decimal('posted_delta', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
            $table->index(['stock_count_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};

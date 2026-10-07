<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily BI metric rollups (02-119 Sales Trend; also feeds purchase trend,
 * cash-flow forecast and branch comparison). Values are materialized from
 * the source documents — never edited by hand — so reports that read this
 * table stay equal to their source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('metric_date');
            $table->string('metric', 32); // invoice_revenue|invoice_count|...
            $table->decimal('value', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'metric_date', 'metric']);
            $table->index(['company_id', 'metric', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_metrics_daily');
    }
};

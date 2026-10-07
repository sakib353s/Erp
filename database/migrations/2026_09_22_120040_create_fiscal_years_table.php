<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal year periods (Phase 3 §A). Bangladesh FY starts 1 July by default
 * (configurable on companies.fiscal_year_start_month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open'); // open|closed
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};

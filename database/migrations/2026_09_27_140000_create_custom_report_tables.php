<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom report infrastructure (02-120): DB-driven report definitions,
 * per-user saved filters, schedules and run history with snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('source', 32);
            $table->json('columns');
            $table->json('filters')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'report_definition_id', 'name']);
        });

        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('frequency', 16); // daily|weekly|monthly
            $table->timestamp('next_run_at');
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('completed');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('snapshot')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('saved_filters');
        Schema::dropIfExists('report_definitions');
    }
};

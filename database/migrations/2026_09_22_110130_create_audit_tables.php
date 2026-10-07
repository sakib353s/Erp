<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident audit foundation (Rule 16, decision D14).
 * Per-company monotonic sequence + SHA-256 hash chain (prev_hash → row_hash).
 * Actor/entity/branch ids are intentionally NOT foreign keys so the trail
 * survives entity deletion (history must never be rewritten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('seq');              // per-company sequence (unique)
            $table->string('action', 64);                   // auth.login, record.update, approval.approve...
            $table->string('actor_type', 16)->default('user'); // user|system|public
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 191)->nullable();  // denormalised name at action time

            $table->string('entity_type', 96)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 191)->nullable();
            $table->string('correlation_id', 36)->nullable();

            $table->json('before')->nullable();             // redacted snapshot
            $table->json('after')->nullable();              // redacted snapshot
            $table->decimal('amount', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->string('result', 16)->default('success'); // success|failure|denied
            $table->string('reason', 191)->nullable();

            $table->char('prev_hash', 64)->nullable();      // null for genesis row
            $table->char('row_hash', 64);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'seq']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('audit_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('period', 16);                   // e.g. 2026-09
            $table->unsignedBigInteger('seq_from');
            $table->unsignedBigInteger('seq_to');
            $table->unsignedInteger('event_count');
            $table->char('chain_start_hash', 64);
            $table->char('chain_end_hash', 64);
            $table->char('checksum', 64);
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_archives');
        Schema::dropIfExists('audit_events');
    }
};

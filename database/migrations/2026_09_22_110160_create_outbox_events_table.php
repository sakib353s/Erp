<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (Rule I + decision D13): domain events are written
 * in the SAME transaction as the business change; a queued job dispatches
 * them post-commit with retry/backoff. Truthful states only —
 * pending|processing|dispatched|failed|discarded, never fake success.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('aggregate_type', 96)->nullable();
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->string('event_type', 191);   // FQCN of the domain event
            $table->json('payload');

            $table->string('status', 16)->default('pending'); // pending|processing|dispatched|failed|discarded
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->char('idempotency_key', 64)->nullable();  // dedupe identical publications

            $table->timestamps();

            $table->unique(['idempotency_key']);
            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};

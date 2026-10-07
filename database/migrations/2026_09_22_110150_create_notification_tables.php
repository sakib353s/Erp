<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-driven notification centre (Rule J): unread/read state,
 * priority, event type, recipient, persistence and per-user channel
 * preferences. External channels stay adapter-gated and truthful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('event_type', 64);      // approval.pending|security.alert|system.info...
            $table->string('channel', 16)->default('inapp');
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('priority', 16)->default('normal'); // low|normal|high|critical
            $table->string('action_url', 191)->nullable();
            $table->json('data')->nullable();
            $table->boolean('persistent')->default(false);     // stays until explicitly handled
            $table->char('dedupe_key', 64)->nullable();        // idempotent fan-out (Rule 12/I)

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['company_id', 'event_type']);
            $table->index(['user_id', 'priority']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('event_type', 64);
            $table->string('channel', 16);            // inapp|email|sms|whatsapp
            $table->boolean('is_enabled')->default(true);
            $table->string('priority_floor', 16)->default('low'); // only >= this priority delivered

            $table->timestamps();

            $table->unique(['user_id', 'event_type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};

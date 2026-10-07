<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security foundation (spec section C/H): authentication event trail,
 * security alerts (suspicious logins, lockouts, permission anomalies)
 * and idempotency keys (Rule 12 — safe retries without duplicate effects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event_type', 32);   // login_success|login_failed|logout|lockout|password_changed|suspicious
            $table->string('email_attempt', 191)->nullable(); // identifier attempted (never a password)
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 191)->nullable();
            $table->string('reason', 128)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['email_attempt', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('alert_type', 64);   // login.new_device|login.locked|permission.denied.spike...
            $table->string('severity', 16)->default('medium'); // low|medium|high|critical
            $table->string('title', 191);
            $table->text('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('context')->nullable();

            $table->string('status', 16)->default('open'); // open|acknowledged|resolved
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'severity']);
            $table->index(['alert_type', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 96);        // logical operation / endpoint
            $table->string('key', 191);         // client-supplied or system key
            $table->char('request_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('completed'); // in_progress|completed|failed
            $table->json('response_snapshot')->nullable();
            $table->timestamps();
            $table->timestamp('expires_at');

            $table->unique(['scope', 'key']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('security_alerts');
        Schema::dropIfExists('auth_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-10…02-12 bulk customer messaging: message templates (structural
 * system rows + company copies), the outbox_messages queue (truthful
 * states — not_configured when no SMS provider has real credentials,
 * queued when one does; NEVER a fabricated sent), and delivery_logs
 * for per-attempt delivery history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('channel', 16); // sms|whatsapp|email
            $table->string('name', 120);
            $table->text('body');
            $table->string('subject', 255)->nullable(); // email channel
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code', 'channel']);
            $table->index(['channel', 'is_active']);
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('channel', 16); // sms|whatsapp|email
            $table->foreignId('message_template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->string('recipient', 160);
            $table->string('subject', 255)->nullable(); // email
            $table->text('body');
            $table->string('status', 20)->default('queued'); // queued|not_configured|sent|failed|cancelled
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_ref', 128)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'channel', 'status']);
            $table->index(['sales_order_id']);
        });

        Schema::create('delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('outbox_message_id')->constrained('outbox_messages')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 16); // sent|failed|skipped
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_body', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['outbox_message_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_logs');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('message_templates');
    }
};

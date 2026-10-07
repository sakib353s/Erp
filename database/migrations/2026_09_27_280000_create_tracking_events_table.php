<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('event_code', 40);
            $table->string('description', 500);
            $table->string('location', 150)->nullable();
            $table->dateTime('occurred_at');
            $table->string('source', 16); // manual|webhook
            $table->string('external_event_id', 100)->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['shipment_id', 'occurred_at']);
        });

        // Signing secret for courier webhooks (02-90). Stored through the
        // model's encrypted cast — plaintext never reaches the table.
        Schema::table('couriers', function (Blueprint $table): void {
            $table->string('webhook_secret', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table): void {
            $table->dropColumn('webhook_secret');
        });

        Schema::dropIfExists('tracking_events');
    }
};

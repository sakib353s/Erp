<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-96 failed_deliveries: one row per failed delivery attempt.
 * Opened automatically when a tracking observation moves the shipment
 * to delivery_failed (TrackShipmentEvent), resolved as
 * retried/returned/reshipped by the recovery actions. attempt_no is
 * per shipment: a retried shipment that fails again opens attempt 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->unsignedTinyInteger('attempt_no')->default(1);
            $table->string('status', 24)->default('open');
            $table->text('reason')->nullable();
            $table->timestamp('failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shipment_id', 'attempt_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_deliveries');
    }
};

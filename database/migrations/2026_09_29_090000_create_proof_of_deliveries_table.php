<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-95 proof_of_deliveries: one row per delivered shipment with the
 * captured proof — signature/photo point at documents rows (safe
 * upload pipeline), receiver name and notes for the human record.
 * The shipment link is unique: exactly one POD per shipment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_of_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->timestamp('delivered_at');
            $table->string('receiver_name', 191)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('signature_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('photo_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('shipment_id');
            $table->index(['company_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
    }
};

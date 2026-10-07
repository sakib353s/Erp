<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->string('status', 24)->default('pending_dispatch'); // pending_dispatch|assigned
            $table->string('external_ref', 128)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('rider_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('rider_name', 64);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_assignments');
        Schema::dropIfExists('shipments');
    }
};

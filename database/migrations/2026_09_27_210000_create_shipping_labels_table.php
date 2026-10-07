<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('label_no', 48);
            $table->string('receiver_name', 120);
            $table->string('receiver_phone', 32);
            $table->string('receiver_address', 255);
            $table->string('district', 64);
            $table->string('parcel_description', 255)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->string('tracking_code', 128)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'label_no']);
            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};

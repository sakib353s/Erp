<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->timestamps();

            $table->unique(['shipment_id', 'product_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};

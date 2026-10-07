<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_zone_id')->constrained('delivery_zones')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('description', 500)->nullable();
            $table->decimal('weight_from', 18, 3)->default(0);
            $table->decimal('weight_to', 18, 3)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'delivery_zone_id', 'code']);
            $table->index(['delivery_zone_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_charges');
    }
};

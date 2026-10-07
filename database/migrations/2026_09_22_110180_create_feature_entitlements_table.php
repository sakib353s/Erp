<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature entitlements mirrored into the tenant DB by the platform control
 * plane (clarification C3). Menu rendering and authorization consult these
 * rows locally — the tenant never calls out to the platform at request time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('feature_key', 96);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('source', 16)->default('platform'); // platform|trial|manual
            $table->timestamps();

            $table->unique(['company_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_entitlements');
    }
};

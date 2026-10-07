<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal registry. Menu visibility, authentication area and feature surface
 * are portal-aware (spec section E corrections: supplier portal is
 * permission/configuration driven; there is NO broad customer portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->string('description', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portals');
    }
};

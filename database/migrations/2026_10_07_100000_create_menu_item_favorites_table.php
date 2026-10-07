<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned navigation destinations (§18.3 "favorites"), curated per user.
 *
 * Added by the UI/navigation redesign: the removed wall-of-text sidebar used
 * to expose ~500 catalog leaves; operators now pin the handful of screens they
 * live in, and the pinned set renders above the curated sections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_favorites');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-driven translations (English + Bengali) — decision D13/D23:
 * UI strings (menus, labels) live in the database, not in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->char('locale', 5);           // en|bn
            $table->string('translation_group', 64)->default('default');
            $table->string('translation_key', 191);
            $table->text('value');
            $table->timestamps();

            $table->unique(['locale', 'translation_group', 'translation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};

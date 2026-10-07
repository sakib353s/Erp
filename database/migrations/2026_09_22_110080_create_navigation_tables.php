<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-driven navigation registry (Rule 6/7, spec section F + §47).
 * The complete supplied menu catalog is stored here; `status='planned'`
 * entries are catalogued but NEVER rendered (clarification C1), so the
 * sidebar never shows dead placeholder links while later phases activate
 * them via `php artisan menu:sync`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->string('label_key', 96);
            $table->string('icon', 32)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->nullable()->constrained('modules')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->nullOnDelete();

            $table->string('code', 160)->unique();          // sales.orders.all.create
            $table->string('label', 191);
            $table->string('label_key', 191)->nullable();    // menu.sales.orders.all.create
            $table->string('route', 191)->nullable();
            $table->string('icon', 64)->nullable();

            $table->foreignId('permission_id')->nullable()->constrained('permissions')->restrictOnDelete();
            $table->string('location', 16)->default('sidebar'); // sidebar|header|utility
            $table->string('status', 16)->default('planned');   // active|planned (C1)
            $table->string('entity_type', 96)->nullable();
            $table->string('action', 32)->nullable();
            $table->string('feature_key', 64)->nullable();      // feature entitlement gate
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module_id', 'status']);
            $table->index(['parent_id', 'sort']);
            $table->index(['status', 'location']);
        });

        Schema::create('menu_item_portal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('portal_id')->constrained('portals')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['menu_item_id', 'portal_id']);
        });

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();             // e.g. branch_activity (composite per §48-A)
            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('label', 128);
            $table->string('label_key', 128)->nullable();
            $table->string('container', 32)->default('dashboard');
            $table->foreignId('permission_id')->nullable()->constrained('permissions')->restrictOnDelete();
            $table->string('feature_key', 64)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
        Schema::dropIfExists('menu_item_portal');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('modules');
    }
};

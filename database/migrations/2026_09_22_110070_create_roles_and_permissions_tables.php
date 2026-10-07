<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-driven RBAC (Rule 6). Permission DEFINITIONS are system-global
 * rows with stable keys (labels translatable); roles and their assignment
 * are company-scoped. user_permission supports explicit grant/deny so a
 * deny always wins over a role grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();           // e.g. sales.orders.create
            $table->string('module', 32)->nullable();        // dashboard|sales|...|system
            $table->string('resource', 64)->nullable();      // orders, users, roles...
            $table->string('action', 32)->nullable();        // view|create|approve|print...
            $table->string('label', 191);
            $table->string('description', 191)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->index(['module', 'resource']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 64);
            $table->string('slug', 64);
            $table->string('description', 191)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['permission_id', 'role_id']);
        });

        Schema::create('user_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 8)->default('grant'); // grant|deny
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};

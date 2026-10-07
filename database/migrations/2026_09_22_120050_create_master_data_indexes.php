<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for Phase 3 master-data and access-control hot paths
 * (§AA). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'branch_scope'], 'users_company_status_scope_idx');
            $table->index('email');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->index(['company_id', 'is_system']);
            $table->index(['company_id', 'can_approve']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->index(['module', 'action']);
            $table->index('key');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->index(['status', 'location', 'is_active']);
            $table->index('permission_id');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->index(['company_id', 'is_active', 'sort']);
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->index(['company_id', 'tax_type', 'is_active']);
        });

        Schema::table('couriers', function (Blueprint $table) {
            $table->index(['company_id', 'is_active', 'sort']);
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->index(['name']);
            $table->index(['division']);
        });

        Schema::table('upazilas', function (Blueprint $table) {
            $table->index(['district_id', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'is_technician']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['company_id', 'is_technician']);
        });
        Schema::table('upazilas', function (Blueprint $table) {
            $table->dropIndex(['district_id', 'is_active']);
        });
        Schema::table('districts', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['division']);
        });
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_active', 'sort']);
        });
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'tax_type', 'is_active']);
        });
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_active', 'sort']);
        });
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex(['status', 'location', 'is_active']);
            $table->dropIndex(['permission_id']);
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['module', 'action']);
            $table->dropIndex(['key']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_system']);
            $table->dropIndex(['company_id', 'can_approve']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_company_status_scope_idx');
            $table->dropIndex(['email']);
        });
    }
};

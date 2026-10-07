<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 enhancements:
 * - companies: logo/seal document links + business preferences columns
 * - branches: manager, open/close dates, cash account reference
 * - roles: configurable branch/warehouse/portal/approval scopes
 * - users: avatar, suspend timestamp, notification prefs flag
 * - settings: user-scope support (user_id column)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('logo_document_id')->nullable()->after('bin')
                ->constrained('documents')->nullOnDelete();
            $table->foreignId('seal_document_id')->nullable()->after('logo_document_id')
                ->constrained('documents')->nullOnDelete();
            $table->string('date_format', 32)->nullable()->after('locale');
            $table->string('number_format', 32)->nullable()->after('date_format');
            $table->boolean('lakh_crore_display')->default(true)->after('number_format');
            $table->boolean('bengali_numerals')->default(false)->after('lakh_crore_display');
            $table->json('business_preferences')->nullable()->after('bengali_numerals');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('email')
                ->constrained('users')->nullOnDelete();
            $table->date('opened_on')->nullable()->after('manager_id');
            $table->date('closed_on')->nullable()->after('opened_on');
            $table->string('notes', 500)->nullable()->after('closed_on');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('branch_scope', 16)->default('assigned')->after('description');
            $table->string('warehouse_scope', 24)->default('all_within_branch')->after('branch_scope');
            $table->string('portal_scope', 32)->nullable()->after('warehouse_scope');
            $table->boolean('can_approve')->default(false)->after('portal_scope');
            $table->unsignedSmallInteger('approval_level')->nullable()->after('can_approve');
            $table->json('data_visibility')->nullable()->after('approval_level');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 255)->nullable()->after('phone');
            $table->timestamp('suspended_at')->nullable()->after('locked_until');
            $table->string('suspension_reason', 255)->nullable()->after('suspended_at');
            $table->boolean('notify_email')->default(true)->after('locale');
            $table->boolean('notify_inapp')->default(true)->after('notify_email');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('branch_id')
                ->constrained('users')->nullOnDelete();
            $table->dropUnique(['company_id', 'branch_id', 'setting_group', 'setting_key']);
            $table->unique(['company_id', 'branch_id', 'user_id', 'setting_group', 'setting_key'],
                'settings_scope_unique');
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_scope_unique');
            $table->dropIndex(['company_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['company_id', 'branch_id', 'setting_group', 'setting_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'suspended_at', 'suspension_reason', 'notify_email', 'notify_inapp']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['branch_scope', 'warehouse_scope', 'portal_scope', 'can_approve', 'approval_level', 'data_visibility']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['opened_on', 'closed_on', 'notes']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_document_id');
            $table->dropConstrainedForeignId('seal_document_id');
            $table->dropColumn(['date_format', 'number_format', 'lakh_crore_display', 'bengali_numerals', 'business_preferences']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP columns on the framework's users table: company scoping, account
 * status/lock policy, branch/warehouse scope, password policy state.
 * No TOTP/MFA fields are added (decision D12 — TOTP explicitly out).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->after('id')->constrained()->restrictOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('company_id');
            $table->string('status', 16)->default('active')->after('is_super_admin'); // active|locked|disabled
            $table->string('phone', 32)->nullable()->after('email');

            $table->string('branch_scope', 16)->default('assigned')->after('status');   // assigned|all
            $table->string('warehouse_scope', 24)->default('all_within_branch')->after('branch_scope'); // assigned|all_within_branch
            $table->foreignId('default_branch_id')->nullable()->after('warehouse_scope')->constrained('branches')->nullOnDelete();

            $table->boolean('must_change_password')->default(false)->after('default_branch_id');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->json('password_history')->nullable()->after('password_changed_at');

            $table->timestamp('last_login_at')->nullable()->after('password_history');
            $table->unsignedInteger('failed_login_count')->default(0)->after('last_login_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count');

            $table->char('locale', 5)->nullable()->after('locked_until');

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'branch_scope']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_branch_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['company_id', 'branch_scope']);
            $table->dropColumn([
                'is_super_admin', 'status', 'phone', 'branch_scope', 'warehouse_scope',
                'must_change_password', 'password_changed_at', 'password_history',
                'last_login_at', 'failed_login_count', 'locked_until', 'locale',
            ]);
        });
    }
};

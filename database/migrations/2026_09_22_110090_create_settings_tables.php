<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings foundation — real relational rows (group + key + typed value),
 * company or branch scoped, with encryption flag, protection flag for
 * invariant keys, editor attribution and history (config changes audited
 * via both setting_history and audit_events).
 *
 * branch_id uses a 0 sentinel for company-scope rows so the composite
 * UNIQUE index works under both MySQL and SQLite (NULL columns would not
 * participate in uniqueness). Branch ids > 0 are validated by
 * SettingService; this is the one deliberate non-FK column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->default(0); // 0 = company scope (sentinel)

            $table->string('setting_group', 48); // general|security|notifications|...
            $table->string('setting_key', 128);
            $table->text('value')->nullable();
            $table->string('value_type', 16)->default('string'); // string|int|bool|json|decimal|encrypted
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_protected')->default(false);

            $table->timestamp('effective_from')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'setting_group', 'setting_key']);
            $table->index(['company_id', 'setting_group']);
        });

        Schema::create('setting_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 36)->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['setting_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_history');
        Schema::dropIfExists('settings');
    }
};

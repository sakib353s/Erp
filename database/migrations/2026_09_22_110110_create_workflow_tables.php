<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic database-driven workflow engine (Rule 15, spec section G).
 * Definitions + versions + states + transitions + conditions + approver
 * rules. No business module may hard-code thresholds, routing or
 * approvers — everything resolves from these tables at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('entity_type', 64);   // e.g. purchase_order, expense_claim, approval_test
            $table->string('action', 48);        // e.g. approve, post, pay
            $table->string('name', 191);
            $table->string('description', 191)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);       // higher wins when conditions tie
            $table->unsignedInteger('current_version')->default(1);
            $table->string('approval_mode', 16)->default('sequential'); // sequential|parallel
            $table->boolean('block_self_approval')->default(true);

            $table->unsignedSmallInteger('due_hours')->nullable();      // per-step SLA
            $table->unsignedSmallInteger('escalation_hours')->nullable();// 0/null = no escalation
            $table->foreignId('escalation_role_id')->nullable()->constrained('roles')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'entity_type', 'action', 'name']);
            $table->index(['company_id', 'entity_type', 'action', 'is_active']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');               // full definition snapshot (immutability record)
            $table->char('snapshot_hash', 64);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workflow_definition_id', 'version']);
        });

        Schema::create('workflow_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('label', 96);
            $table->string('kind', 16)->default('normal'); // initial|normal|terminal
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'code']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->foreignId('from_state_id')->nullable()->constrained('workflow_states')->nullOnDelete();
            $table->foreignId('to_state_id')->constrained('workflow_states')->restrictOnDelete();
            $table->string('action_code', 48);   // submit|approve|reject|return|cancel|confirm
            $table->string('label', 96);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workflow_definition_id', 'action_code']);
        });

        Schema::create('workflow_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('subject', 64);        // amount|branch_id|role|entity_type|currency...
            $table->string('operator', 16);       // gte|lte|eq|neq|in|not_in|between
            $table->string('value_string', 191)->nullable();
            $table->decimal('value_min', 18, 4)->nullable();
            $table->decimal('value_max', 18, 4)->nullable();
            $table->json('value_list')->nullable(); // for in / not_in
            $table->unsignedSmallInteger('condition_group')->default(0); // AND inside group, OR across groups
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workflow_definition_id', 'condition_group']);
        });

        Schema::create('workflow_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('level')->default(1);   // approval level (amount thresholds imply levels)
            $table->string('approver_type', 16);                 // role|user|requester_manager
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('branch_rule', 32)->default('any');   // any|request_branch|user_branches
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workflow_definition_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approvers');
        Schema::dropIfExists('workflow_conditions');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};

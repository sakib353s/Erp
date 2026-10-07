<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime approval instances (Rule 15): requests, steps, full action
 * history (incl. comments) and delegation. Requests carry an immutable
 * payload snapshot + hash so post-submission tampering is detectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->foreignId('workflow_definition_id')->nullable()->constrained('workflow_definitions')->nullOnDelete();
            $table->unsignedInteger('workflow_version')->default(1);

            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 48);
            $table->string('reference_no', 96)->nullable();
            $table->string('subject', 191);
            $table->decimal('amount', 18, 4)->nullable();   // threshold matching
            $table->char('currency', 3)->default('BDT');

            $table->string('status', 16)->default('pending'); // pending|approved|rejected|returned|cancelled
            $table->unsignedSmallInteger('current_level')->default(1);
            $table->unsignedSmallInteger('revision')->default(1);  // increments on resubmit after return
            $table->unsignedSmallInteger('return_count')->default(0);

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->json('snapshot')->nullable();      // entity payload snapshot at submission
            $table->char('snapshot_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'action', 'revision']);
            $table->index(['company_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['submitted_by', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('status', 16)->default('pending'); // pending|approved|rejected|returned|skipped|cancelled

            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('is_required')->default(true);

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['approval_request_id', 'level']);
            $table->index(['status', 'due_at']);
            $table->index(['approver_user_id', 'status']);
            $table->index(['approver_role_id', 'status']);
        });

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('approval_step_id')->nullable()->constrained('approval_steps')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);    // submit|approve|reject|return|comment|delegate|escalate|cancel
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['approval_request_id', 'created_at']);
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 64)->nullable(); // null = any entity type
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegate_user_id', 'is_active']);
            $table->index(['delegator_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
    }
};

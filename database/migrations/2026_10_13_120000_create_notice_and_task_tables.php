<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-12 notice board and §12-13 tasks & projects.
 *
 * The two things an office runs on that are not documents: what everybody
 * needs to know, and who is doing what by when. Both are deliberately *not*
 * notification tables — a notification is a delivery of a message to a person
 * (`notifications`), while a notice is the message itself and a task is
 * a piece of work with a state. Publishing a notice fans notifications
 * out; the notice survives the fan-out, and who has *acknowledged* it is
 * its own ledger row rather than a `read_at` on somebody's inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('title', 191);
            $table->text('body');
            $table->string('category', 32)->default('general'); // general|policy|urgent|hr|finance

            /*
             * The audience is stored, not recomputed: a notice sent to the
             * Dhaka outlet in October must still say so in December, even after
             * somebody joins or leaves. `audience` holds the ids the publisher
             * chose; `audience_type` says what they mean.
             */
            $table->string('audience_type', 16)->default('all'); // all|roles|branches|users
            $table->json('audience')->nullable();

            $table->string('status', 16)->default('draft'); // draft|published|archived
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('requires_acknowledgement')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status', 'published_at']);
        });

        Schema::create('notice_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // One acknowledgement per person per notice: re-opening the page is
            // not a second reading, and the tracking table is a ledger.
            $table->unique(['notice_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active'); // active|on_hold|completed
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo'); // todo|in_progress|blocked|review|done|cancelled
            $table->string('priority', 16)->default('normal'); // low|normal|high|urgent

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'status', 'due_at']);
            $table->index(['company_id', 'assigned_to', 'status']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        /*
         * Every state change, its author and its reason. A task that has moved
         * from “blocked” back to “in progress” is a fact somebody will need to
         * explain later, and the audit chain records *that* something changed
         * while this records what the workflow actually did.
         */
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32); // created|assigned|status_changed|commented|due_changed
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('notice_acknowledgements');
        Schema::dropIfExists('notices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-11 — meetings, the minutes they produce, and the work that comes out of them.
 *
 * Three tables and one column, and the column is the interesting one: an action
 * item is a **task** (`tasks.meeting_id`), not a row of its own. An action item
 * that lives in its own table is a second to-do list — it does not appear on the
 * task board, it does not appear in “my tasks”, its overdue flag has to be
 * computed twice and its history diverges. What a meeting produces is work, and
 * this application already knows how to carry work: states, transitions, an event
 * log, notifications and a board. The meeting keeps a link to it, which is what
 * lets the minutes page say “this is what came of that meeting” without keeping a
 * parallel copy that can disagree.
 *
 * A meeting itself is small: when, where, who, what was said, and the four things
 * that can happen to it — it is scheduled, it is moved, it is held, it is
 * cancelled. Attendance is a fact about a person on a day (present, absent,
 * apology) and it is recorded *after* the meeting, which is why it sits on the
 * attendee row rather than on the meeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->text('agenda')->nullable();
            $table->string('location', 160)->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->string('status', 16)->default('scheduled'); // scheduled|held|cancelled
            $table->timestamp('held_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 255)->nullable();

            // The minutes are the record of what happened. Empty minutes on a held
            // meeting are a real state — the meeting happened and nobody wrote it
            // down yet — and the screen says so rather than pretending otherwise.
            $table->text('minutes')->nullable();
            $table->timestamp('minutes_recorded_at')->nullable();
            $table->foreignId('minutes_recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('chaired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
            $table->index(['company_id', 'status', 'starts_at']);
        });

        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // An outside person can be on the list — the auditor, the supplier, the
            // landlord. They have no account, so they have a name and the meeting
            // still records that they were there.
            $table->string('name', 160)->nullable();

            $table->string('role', 16)->default('attendee'); // chair|secretary|attendee
            $table->string('response', 16)->default('pending'); // pending|accepted|declined
            $table->timestamp('responded_at')->nullable();

            // Recorded after the meeting: null means not marked yet, which is not
            // the same as absent.
            $table->string('attendance', 16)->nullable(); // present|absent|apology
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
            $table->index(['meeting_id', 'attendance']);
        });

        Schema::create('meeting_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32); // scheduled|rescheduled|held|cancelled|attendance|minutes|action_item|responded
            $table->timestamp('happened_at');
            $table->string('note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['meeting_id', 'happened_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // §12-11: an action item from a meeting is a task that remembers where
            // it came from. Nothing else about a task changes.
            $table->foreignId('meeting_id')->nullable()->after('project_id')
                ->constrained('meetings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_id');
        });

        Schema::dropIfExists('meeting_events');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};

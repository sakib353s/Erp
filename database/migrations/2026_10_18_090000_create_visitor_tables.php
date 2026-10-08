<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-16 — the visitor desk: who came in, who is expected, and who is still
 * inside the building.
 *
 * Two tables, because the two questions are different. `visitors` is the
 * *person* — a name, a way to reach them, and the papers that identify them,
 * which is the same row whether they came in last week or are booked in for
 * Thursday. `visitor_visits` is the *event*: a day, a host, a purpose, a badge,
 * a time in, a time out.
 *
 * Keeping them apart is what makes the register worth reading. A person's row
 * accumulates their history (how often, who do they come to see, are they ever
 * sent away again), while a visit is closed and never rewritten; the reports
 * lens is then a question about visits and the blacklist is a question about a
 * person. One table holding both would have meant losing the history every time
 * somebody checked out.
 *
 * `status` is the life cycle — expected → inside → out, with cancelled and
 * no_show for the two ways a booking ends without anybody walking through the
 * door. Nothing about how long somebody stayed is stored: `checked_in_at` and
 * `checked_out_at` are timestamps, and the minutes between them are arithmetic.
 *
 * No global branch scope is applied — §12 filters explicitly (`visible()`), the
 * way the asset register and the utility desk do, so a company-wide reader is
 * not silently narrowed to the branch they happen to be standing in. The
 * branch column is still there and still honoured, because a gate is a place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('organisation', 160)->nullable();   // who they represent

            // The papers. Held here because a gate asks for them once and a
            // security desk may have to answer later who came in.
            $table->string('id_type', 24)->nullable();          // nid|passport|driving|other
            $table->string('id_number', 64)->nullable();

            // The one thing a gate needs to know before opening it again.
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_reason', 500)->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'is_blacklisted']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('visitor_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The booking. Null means nobody booked this — somebody walked in.
            $table->dateTime('scheduled_for')->nullable();

            $table->string('purpose', 24);                      // meeting|delivery|interview|service|vendor|other
            $table->string('badge_no', 32)->nullable();          // issued at check-in, per company per day
            $table->string('meet_at', 120)->nullable();          // reception, floor, cabin
            $table->string('items_carried', 500)->nullable();
            $table->string('vehicle_no', 32)->nullable();

            // Life cycle only. How long somebody stayed is arithmetic on the
            // two timestamps below, never a column that can disagree with them.
            $table->string('status', 16)->default('expected');   // expected|inside|out|no_show|cancelled

            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // One badge number per company: VB-20261008-03 is issued once and
            // the row that holds it is the answer to "who was that?".
            $table->unique(['company_id', 'badge_no']);
            $table->index(['company_id', 'status', 'scheduled_for']);
            $table->index(['company_id', 'visitor_id']);
            $table->index(['company_id', 'branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_visits');
        Schema::dropIfExists('visitors');
    }
};

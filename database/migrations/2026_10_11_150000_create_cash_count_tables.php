<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-05 — counting the drawer.
 *
 * Every other screen in this module believes the ledger. This one does not: it
 * puts a person in front of the tin with a pile of notes and asks what is
 * actually there, because the one thing a book cannot audit is itself. Three
 * decisions make the answer usable:
 *
 *  · **The expected figure is frozen on the count.** The ledger's balance for
 *    the account on the day of the count is copied onto the row, so a count
 *    reads the same next year as it did then — and a count that was disputed
 *    can be read for what it was judged against rather than what the books say
 *    afterwards.
 *  · **The variance is the only thing that posts.** A count does not restate
 *    the sales that made the drawer short; it corrects the *cash account* by the
 *    difference, to cash over & short on the shortage side and other income on
 *    the overage side — the same two accounts the COD remittance desk already
 *    uses, so the two desks cannot disagree about what a short drawer means.
 *    An exact count posts nothing at all, because there is nothing to correct.
 *  · **Above the tolerance the correction waits.** The number the count was
 *    judged against is stored beside it, and a variance at or above it is
 *    recorded and *not* posted until somebody other than the counter approves
 *    it — money that is missing must not be written off by the person who was
 *    holding it.
 *
 * The denominations are lines rather than a single figure so a count can be
 * added up again by whoever reads it: notes and coins with face values and
 * quantities, and the service refuses a breakdown that does not add up to the
 * counted total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // The drawer being counted — a cash account, never a bank or wallet.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->date('counted_on');

            // What the books said that day, what was in the tin, and the gap.
            // All three are stored: the first because the ledger moves on, the
            // other two because they are what a person actually counted.
            $table->decimal('expected_amount', 18, 4);
            $table->decimal('counted_amount', 18, 4);
            $table->decimal('variance', 18, 4);

            // The number this count was judged against, copied for the same
            // reason the expense desk copies its approval limit.
            $table->decimal('tolerance', 18, 4)->default(0);

            // pending_approval → posted · rejected
            $table->string('status', 24);

            // Why the tin does not match. Demanded whenever there is a gap, on
            // the record, where the next person to count that drawer will find it.
            $table->string('difference_reason', 300)->nullable();
            $table->string('notes', 300)->nullable();

            // The correction, once there is one — null for an exact count and
            // null while a variance waits for a signature.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'counted_on']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('cash_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_count_id')->constrained('cash_counts')->cascadeOnDelete();

            $table->string('kind', 8);                  // note | coin
            $table->decimal('face_value', 18, 4);
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 18, 4);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['cash_count_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_lines');
        Schema::dropIfExists('cash_counts');
    }
};

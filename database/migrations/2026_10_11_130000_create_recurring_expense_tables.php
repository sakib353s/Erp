<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-19 — expenses that happen every month whether anybody remembers or not.
 *
 * Rent, salaries, the internet line and the security guard's wages do not need
 * to be discovered: they are known in advance, and a desk that asks somebody to
 * retype them every month is a desk that eventually forgets one. A recurring
 * expense is therefore a *schedule*, not a posting — nothing in this table has
 * touched the ledger.
 *
 * Two decisions are recorded here because they are the whole feature:
 *
 *  · `next_due_on` is a date, not "the first of the month". A monthly schedule
 *    keeps the day it was created with and clamps to the end of a short month
 *    (a schedule on the 31st falls on the 28th in February and returns to the
 *    31st in March), because a rent day is a real day in a real contract.
 *  · `last_generated_on` plus the unique (expense, date) index on `expenses`
 *    make a second run a no-op. A generator that can post the same month twice
 *    because somebody ran it twice is worse than no generator at all.
 *
 * What it does NOT do is post quietly: a generated expense goes through the
 * ordinary expense path, which means the approval gate applies to it exactly as
 * it applies to one somebody typed. Automation must never be a way around a
 * signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('payee', 160);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('narration', 500)->nullable();

            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->default('BDT');
            $table->string('settled_with', 12)->default('money');
            $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('frequency', 12);                    // monthly|weekly|quarterly|yearly
            $table->unsignedTinyInteger('day_of_month')->nullable();  // 1–31, clamped by the month
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->date('next_due_on');
            $table->date('last_generated_on')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'next_due_on']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            // Which schedule produced this expense, if one did. Nullable because
            // most expenses are typed by a person.
            $table->foreignId('recurring_expense_id')->nullable()->after('id')
                ->constrained('recurring_expenses')->nullOnDelete();

            // One expense per schedule per generation date. This is the index a
            // double run hits, and the reason the second run is a no-op rather
            // than a second month of rent in the books.
            $table->unique(['recurring_expense_id', 'expense_date'], 'expenses_recurring_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_recurring_unique');
            $table->dropConstrainedForeignId('recurring_expense_id');
        });

        Schema::dropIfExists('recurring_expenses');
    }
};

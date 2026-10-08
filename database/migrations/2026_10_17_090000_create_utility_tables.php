<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-15 — the utility bills: who supplies the premises, and what they asked for.
 *
 * Two tables, because the two questions are different. `utility_providers` is the
 * *registry* — DESCO and WASA and the landlord are not transactions, they are
 * counterparties with a consumer number, a meter, a premises and — the column
 * that matters — **the ledger account their bills belong in**. `utility_bills` is
 * the month-by-month record: what was consumed, what was asked for, when it is
 * due, whether it has been paid, and which journal entry closed it.
 *
 * The provider carries the account on purpose. A dropdown that let an electricity
 * bill be booked as rent is a dropdown that will eventually do it, and the ledger
 * would then need somebody to notice. The account defaults from the *family*
 * (5230 Utilities for power, water, gas and connectivity; 5220 Rent for the
 * landlord) and can be pointed somewhere else deliberately.
 *
 * Nothing about a bill's state is stored twice: `status` is a life cycle
 * (recorded → paid, or void), and “overdue” is read from `due_date` against the
 * clock, so a register that was only true after the nightly job would not exist
 * here either.
 *
 * No global branch scope is applied to either table — the §12 module filters
 * explicitly (`visible()`), the same way the asset register and the records
 * register do, so a company-wide reader is not silently narrowed to the branch
 * they happen to be standing in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 40);                     // DESCO, WASA, TITAS, RENT-UTTARA…
            $table->string('name', 160);
            $table->string('family', 24);                   // electricity|water|gas|internet|rent

            // The account this provider's bills are booked to. Defaulted from the
            // family when the provider is created; overridable on purpose.
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('consumer_no', 64)->nullable();  // the number on their bill
            $table->string('meter_no', 64)->nullable();
            $table->string('premises', 191)->nullable();    // which address they supply
            $table->unsignedTinyInteger('due_day')->nullable();  // day of the month their bill lands
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'family', 'is_active']);
        });

        Schema::create('utility_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained('utility_providers')->cascadeOnDelete();

            $table->string('bill_no', 32);                  // UB-000001, allocated per company
            $table->char('period_month', 7);                // the month the consumption belongs to
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('consumption', 15, 4)->nullable();
            $table->string('consumption_unit', 16)->nullable();   // kWh|m3|MB|month
            $table->decimal('meter_reading', 15, 3)->nullable();
            $table->string('narration', 500)->nullable();

            // Life cycle only. “Overdue” is the clock's business, not a column's.
            $table->string('status', 24)->default('recorded');    // recorded|pending_approval|paid|void

            $table->boolean('approval_gate')->default(false);
            $table->decimal('approval_threshold', 15, 2)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->date('paid_on')->nullable();
            $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'bill_no']);
            $table->index(['company_id', 'due_date', 'status']);
            $table->index(['company_id', 'provider_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bills');
        Schema::dropIfExists('utility_providers');
    }
};

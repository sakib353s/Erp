<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-15…§08-18 — the expense desk.
 *
 * Two tables, because an expense is always two facts: what it was for and where
 * the money went. `expense_categories` is the first fact made configurable — a
 * category *is* a general-ledger account (§08-17), so nothing in this module
 * ever guesses which account an expense belongs on. Dr the category's account;
 * that is the whole reason categories exist here rather than a free-text type.
 *
 * `expenses` is the second fact. `settled_with` says whether the money has left
 * (`money`) or is owed (`payable`), and the schema keeps the two honest: a paid
 * expense carries the account it was paid from, an unpaid one carries the
 * supplier it is owed to and posts to payables. `approval_gate` and the
 * threshold it was judged against are stored on the row rather than left to the
 * setting, so the reason a given expense had to wait for a signature stays
 * readable after somebody changes the threshold.
 *
 * A pending expense has no journal entry — a document waiting for approval must
 * never look like a document that has posted — which is why `journal_entry_id`
 * arrives with `approved_at` and not before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('code', 32);
            $table->string('name', 120);

            // The account this category books to. Not nullable on purpose: a
            // category that points nowhere is a trap for whoever records the
            // next expense.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('description', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('expense_no', 40);                   // EXP-… allocated here, never reused
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->date('expense_date');
            $table->string('payee', 160);                       // who was paid, or who is owed
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('narration', 500)->nullable();

            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->default('BDT');

            // money → paid from an account now · payable → owed to a supplier
            $table->string('settled_with', 12)->default('money');
            $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // pending_approval → posted|rejected · posted → reversed
            $table->string('status', 24);
            $table->boolean('approval_gate')->default(false);
            $table->decimal('approval_threshold', 18, 4)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();

            // The posting and — if somebody reverses it — its answer. Never a
            // silent edit: the original entry stays where it is.
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_entry_id')->nullable();

            // The photograph of the paper, if there was paper (§08-16). Nullable
            // because a rickshaw fare has no receipt, and a desk that refuses it
            // is a desk that gets bypassed.
            $table->foreignId('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'expense_no']);
            $table->index(['company_id', 'status', 'expense_date']);
            $table->index(['company_id', 'category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};

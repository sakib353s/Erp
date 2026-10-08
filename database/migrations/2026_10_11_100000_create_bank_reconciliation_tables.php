<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-08/09/12 — proving the bank book against the bank's own statement.
 *
 * Four tables, because a reconciliation is a document with an argument inside
 * it. `bank_statement_imports` is the file that was read and what became of it;
 * `bank_statement_lines` are the bank's claims, kept in the bank's own language
 * (a statement `debit` takes money out of our account, which is the opposite of
 * a ledger debit — the service translates, this table does not pretend to);
 * `bank_reconciliations` is the period that was proved, with the figures the
 * proof was made of frozen on the row so a signed-off reconciliation reads the
 * same next year as the day it was signed; and `reconciliation_lines` is the
 * frozen list of everything compared — which book lines and which statement
 * lines were matched, and which were left over on each side.
 *
 * Why the freeze is not optional: matching rules change, statements get
 * re-imported, and a signed reconciliation is a statement about a day. If the
 * lists were recomputed on read, that day would keep changing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('file_name', 160);
            // Only a run that actually stored lines carries a checksum, so the
            // unique index below refuses the *same statement* twice — which would
            // double every line and reconcile to a difference nobody caused —
            // while a preview and a later real import of that file stay legal.
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('status', 16)->default('imported'); // preview|imported|rejected
            $table->json('errors')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'checksum']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->date('value_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 80)->nullable();
            $table->decimal('debit', 18, 4)->default(0);        // statement language: money left the account
            $table->decimal('credit', 18, 4)->default(0);       // statement language: money entered the account
            $table->decimal('balance_after', 18, 4)->nullable(); // when the file carries a running balance
            $table->unsignedInteger('line_no')->nullable();     // the row's own line in the file, for messages
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->string('match_type', 12)->nullable();       // auto|manual
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'value_date']);
            $table->index(['matched_journal_line_id']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            // Money-in figures on both sides, in the books' convention (positive
            // is money we hold). `difference` is what nothing explains — zero on
            // a reconciliation that adds up.
            $table->decimal('statement_opening', 18, 4)->default(0);
            $table->decimal('statement_closing', 18, 4)->default(0);
            $table->decimal('book_opening', 18, 4)->default(0);
            $table->decimal('book_balance', 18, 4)->default(0);
            $table->decimal('unmatched_statement_total', 18, 4)->default(0);
            $table->decimal('unmatched_book_total', 18, 4)->default(0);
            $table->decimal('difference', 18, 4)->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_statement_count')->default(0);
            $table->unsignedInteger('unmatched_book_count')->default(0);
            $table->string('status', 16)->default('open');       // open|balanced|signed_off
            $table->string('notes', 1000)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'period_end']);
        });

        Schema::create('reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->string('side', 12);                          // book|statement
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->unsignedBigInteger('statement_line_id')->nullable();
            $table->date('movement_date');
            $table->string('reference', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('amount', 18, 4);                    // signed in the books' convention
            $table->string('state', 12)->default('unmatched');   // matched|unmatched
            $table->string('match_type', 12)->nullable();        // auto|manual
            $table->timestamps();

            $table->index(['reconciliation_id', 'side', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};

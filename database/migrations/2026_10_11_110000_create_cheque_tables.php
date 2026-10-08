<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-13/08-14 — cheques, in both directions (§08).
 *
 * A cheque is a promise and the register is the record of promises: one the
 * company gave (issued) and one it holds (received). The table keeps them
 * together because the question they answer is the same one — "what is still
 * hanging over this bank account?" — and because a cheque is only money when
 * somebody clears it.
 *
 * The accounting decision this schema records, because it is the whole point:
 * a cheque **does not touch the ledger until it clears**. Money that has been
 * promised is not money in the bank, and posting it early would make the bank
 * book disagree with the bank statement every single month. So `cleared_on` and
 * `journal_entry_id` arrive together, `bounced_on` and `reversal_entry_id`
 * arrive together, and a cheque sitting in the register with neither is exactly
 * what it says it is: a promise nobody has settled yet.
 *
 * The bank's number is the key: a cheque book belongs to one account, and the
 * same leaf cannot be written twice — the service enforces that for issued
 * cheques, where the book is ours, and refuses a probable double-entry for
 * received ones, where the book belongs to whoever wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Which way the promise runs. Received: somebody's cheque is in our
            // drawer. Issued: ours is in somebody else's.
            $table->string('direction', 8);                     // received|issued
            $table->string('cheque_no', 32);                    // the number printed on it by the bank
            $table->date('cheque_date');                        // the date written on it — a future one is post-dated
            $table->string('bank_name', 80);                    // whose book it came out of

            // The money account it will clear through, and what it is for. Both
            // are chosen by the operator: the person holding the slip knows.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('counter_account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('party_name', 160);                  // who wrote it, or who it is made out to
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->default('BDT');

            // received → deposited → cleared|bounced · issued → presented → cleared|returned
            $table->string('status', 16);
            $table->date('deposited_on')->nullable();
            $table->date('presented_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->string('bounced_reason', 300)->nullable();

            // The two postings a cheque can ever make: the settlement, and its
            // reversal when the promise broke. Never one without the other.
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_entry_id')->nullable();

            $table->string('reference', 64)->nullable();
            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Per account and direction: the same leaf cannot be written twice,
            // and the desk refuses a probable double-entry of the same slip.
            $table->unique(['company_id', 'account_id', 'direction', 'cheque_no'], 'cheques_number_unique');
            $table->index(['company_id', 'status', 'cheque_date']);
            $table->index(['company_id', 'direction', 'cheque_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};

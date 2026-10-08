<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §08-21 — the float in the drawer.
 *
 * Petty cash is the one place where an ERP's own rules are tested hardest: the
 * money is small, the vouchers are paper, and the person holding it is usually
 * the person who spends it. Three decisions keep this desk honest:
 *
 *  · **A fund is a real account in the chart of accounts.** Declaring a float
 *    creates a postable cash leaf under Current Assets, named after the fund and
 *    held by a named custodian, so the balance on this screen is the ledger's
 *    own figure rather than a number this desk keeps for itself.
 *  · **A voucher out of the float is a payment, not a parallel money path.** The
 *    disbursement posts Dr the expense category, Cr the float through the same
 *    `MoneyMovementService` every other payment uses, and this table carries what
 *    the ledger cannot say: which fund, which voucher, which request produced it.
 *  · **Replenishment is a transfer into the float** — it moves the imprest level
 *    back up and never doubles as the place where the spending is recorded.
 *
 * The request table is the control layer: above the company's limit a voucher is
 * asked for rather than recorded, and the answer has to come from somebody other
 * than the person who asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name', 120);

            // Who is answerable for the cash. Nullable only for the moment the
            // custodian leaves the company — the desk refuses to declare a fund
            // without one.
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();

            // The float itself: a postable cash account in this company's chart.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            // The level the fund is supposed to hold (the imprest amount). The
            // desk replenishes back to it rather than guessing when to top up.
            $table->decimal('imprest_amount', 18, 4)->default(0);
            $table->char('currency', 3)->default('BDT');

            $table->boolean('is_active')->default(true);
            $table->date('opened_on');
            $table->date('closed_on')->nullable();
            $table->string('description', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        // Asked for before it is spent: the control layer, not the money.
        Schema::create('petty_cash_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('fund_id')->constrained('petty_cash_funds')->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('payee', 160);
            $table->string('narration', 300)->nullable();
            $table->date('needed_on');
            $table->decimal('amount', 18, 4);

            // pending_approval → approved (and paid) · rejected
            $table->string('status', 24);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();

            // The voucher the approval produced, once there is one.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status', 'needed_on']);
            $table->index(['company_id', 'fund_id']);
        });

        // What the ledger cannot say about a movement of float money.
        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('fund_id')->constrained('petty_cash_funds')->restrictOnDelete();

            // disbursement (money left the float) · replenishment (money went in)
            $table->string('kind', 16);
            $table->date('occurred_on');
            $table->decimal('amount', 18, 4);

            // What a disbursement was for — the same category the expense desk
            // uses, so a rickshaw fare booked here and one booked there are the
            // same account in the same report.
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();

            // The money document behind this row: a payment out of the float, or
            // the transfer that topped it up. Never both, never neither.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('cash_transfers')->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('petty_cash_requests')->nullOnDelete();

            // Who was handed the cash. The payment row carries a supplier and
            // nothing else, and a rickshaw fare has no supplier — so the name the
            // voucher was made out to is kept here, where the register reads it.
            $table->string('payee', 160)->nullable();

            $table->string('narration', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'fund_id', 'occurred_on']);
            $table->index(['company_id', 'kind', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_transactions');
        Schema::dropIfExists('petty_cash_requests');
        Schema::dropIfExists('petty_cash_funds');
    }
};

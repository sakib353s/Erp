<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §08 — cash, bank and mobile-wallet accounts, and the transfers between them.
 *
 * A money account is not a new kind of thing: it is an account in the chart of
 * accounts that money can physically sit in. So the registry lives on `accounts`
 * — where every journal line already points — and gains only what a ledger
 * cannot say: which instrument it is (`instrument`), which bank holds it, its
 * number, and which mobile provider it belongs to. The `is_cash` / `is_bank`
 * flags the chart has always carried stay authoritative and are backfilled into
 * the new column, so every reader of those two flags keeps its meaning.
 *
 * `cash_transfers` is its own table rather than two loose journal lines because
 * a transfer between two of the company's own accounts is a document an auditor
 * asks for by number — "show me the 40,000 that moved on the 3rd" — and because
 * it needs an idempotency key of its own: a double-submitted transfer moves the
 * money twice and leaves nothing behind to notice it by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('instrument', 12)->nullable();        // cash|bank|wallet
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 48)->nullable();
            $table->string('wallet_provider', 16)->nullable();   // bkash|nagad|rocket|upay

            $table->index(['company_id', 'instrument']);
        });

        // The two accounts the standard chart already marks keep their meaning.
        DB::table('accounts')->where('is_cash', true)->whereNull('instrument')->update(['instrument' => 'cash']);
        DB::table('accounts')->where('is_bank', true)->whereNull('instrument')->update(['instrument' => 'bank']);

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('transfer_no', 32);
            $table->date('transferred_on');
            $table->decimal('amount', 18, 4);
            $table->string('status', 16)->default('posted'); // posted|reversed
            $table->string('reference', 64)->nullable();
            $table->string('narration', 500)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'transfer_no']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'transferred_on']);
            $table->index(['from_account_id']);
            $table->index(['to_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'instrument']);
            $table->dropColumn(['instrument', 'bank_name', 'account_number', 'wallet_provider']);
        });
    }
};

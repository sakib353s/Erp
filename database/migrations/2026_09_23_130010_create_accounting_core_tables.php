<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting core (Phase D, §7):
 * - account_groups + accounts: DB-driven chart of accounts (system accounts protected)
 * - fiscal_periods: posting gate (closed periods reject new postings)
 * - journal_entries + journal_lines: real double-entry (ΣD = ΣC, checksum, immutable once posted)
 * - posting_rules: source-document event → account-role resolution (controllers never hardcode codes)
 * - cost_centers: dimension tagging on journal lines
 * - payment_allocations: invoice/bill settlement truth
 * - running_balances: derived display cache, rebuildable from journal_lines
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('type', 16); // asset|liability|equity|revenue|expense
            $table->foreignId('parent_id')->nullable()->constrained('account_groups')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 191);
            $table->string('type', 16); // asset|liability|equity|revenue|expense
            $table->string('sub_type', 48)->nullable();
            $table->boolean('is_group')->default(false);       // header (no postings) vs leaf
            $table->boolean('is_system')->default(false);      // protected from delete/reparent
            $table->boolean('is_active')->default(true);
            $table->boolean('is_control_account')->default(false); // AR/AP control
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_bank')->default(false);
            $table->char('currency', 3)->default('BDT');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['account_group_id']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->unsignedSmallInteger('period_no');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open'); // open|closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['fiscal_year_id', 'period_no']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->string('entry_no', 64);
            $table->date('entry_date');
            $table->string('journal_type', 32)->default('manual'); // manual|auto|opening|closing|reversal
            $table->string('source_type', 64)->nullable();         // provenance: Invoice, PurchaseBill, …
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 64)->nullable();        // posting_rules.event_type
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('description', 500);
            $table->string('narration', 500)->nullable();
            $table->decimal('total_debit', 18, 4)->default(0);
            $table->decimal('total_credit', 18, 4)->default(0);
            $table->char('checksum', 64);                          // sha256 of balanced line payload
            $table->string('posting_state', 16)->default('posted'); // draft|posted|reversed
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'entry_no']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'posting_state', 'entry_date']);
            $table->index(['fiscal_period_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['reversal_of_id']);
            $table->index(['branch_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('dc', 8); // debit|credit
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->default('BDT');
            $table->string('party_type', 48)->nullable();  // customer|supplier|employee
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('narration', 500)->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['journal_entry_id', 'line_no']);
            $table->index(['company_id', 'account_id']);
            $table->index(['party_type', 'party_id']);
            $table->index(['cost_center_id']);
        });

        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 64);      // sales_invoice_issued, receipt, expense, …
            $table->string('doc_type', 48)->nullable(); // document_types.code filter (null = any)
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('role', 48);            // ar|cash|bank|sales|tax_payable|cogs|inventory|…
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('side', 8);             // debit|credit
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'event_type', 'doc_type', 'branch_id', 'role', 'position'],
                'posting_rules_uniq_event_role');
            $table->index(['company_id', 'event_type', 'is_active']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');             // payments table arrives Phase G/I
            $table->string('allocatable_type', 64);               // Invoice|PurchaseBill|…
            $table->unsignedBigInteger('allocatable_id');
            $table->decimal('amount', 18, 4);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'payment_id']);
            $table->index(['allocatable_type', 'allocatable_id']);
            $table->index(['payment_id', 'allocatable_type', 'allocatable_id']);
        });

        Schema::create('running_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('debit_total', 18, 4)->default(0);
            $table->decimal('credit_total', 18, 4)->default(0);
            $table->decimal('balance', 18, 4)->default(0);         // signed: debit-normal positive
            $table->string('currency', 3)->default('BDT');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'branch_id', 'currency'],
                'running_balances_uniq');
            $table->index(['company_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('running_balances');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_groups');
        Schema::dropIfExists('cost_centers');
    }
};

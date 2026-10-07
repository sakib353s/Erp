<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-97 COD reconciliation: the office's remittance of rider cash vs
 * what riders recorded as collected (the cash-vs-remittance match).
 * The variance rides on the row (cash over/short posts at finalize);
 * collections point back at their reconciliation so the tracking
 * screen can report outstanding cash truthfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('rider_employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('remitted_amount', 12, 2);
            $table->decimal('cash_total', 12, 2);
            $table->decimal('variance', 12, 2);
            $table->string('status', 24)->default('pending_approval');
            $table->timestamp('remitted_at')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'rider_employee_id']);
        });

        // Nullable back-reference (no DB-level FK: SQLite cannot add one
        // via ALTER; the action keeps the link consistent).
        Schema::table('rider_cod_collections', function (Blueprint $table) {
            $table->unsignedBigInteger('cod_reconciliation_id')
                ->nullable()
                ->after('recorded_by');
            $table->index(
                ['company_id', 'cod_reconciliation_id'],
                'rider_cod_collections_recon_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('rider_cod_collections', function (Blueprint $table) {
            $table->dropIndex('rider_cod_collections_recon_idx');
            $table->dropColumn('cod_reconciliation_id');
        });

        Schema::dropIfExists('cod_reconciliations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-26 — the stock adjustment grows a second pair of eyes.
 *
 * An adjustment is the one document that changes stock with no counterparty and
 * no external paper behind it: a person says "the shelf holds three, the ledger
 * says five". That is exactly the write that needs a reviewer once it is worth
 * enough money, so the document gains the decision fields rather than a separate
 * approval table — the approval is a property of this adjustment, and the
 * history screen reads it straight off the row.
 *
 * `total_value` stores the absolute value the threshold judged, so the reason an
 * adjustment needed approval stays readable after the setting changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->decimal('total_value', 18, 4)->default(0)->after('reason');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('posted_at');
            $table->string('approval_note', 500)->nullable()->after('approved_at');

            $table->index(['company_id', 'status', 'created_at'], 'stock_adjustments_company_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropIndex('stock_adjustments_company_status_created_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['total_value', 'approved_at', 'approval_note']);
        });
    }
};

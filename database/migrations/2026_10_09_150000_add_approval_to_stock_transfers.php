<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-28 — a transfer that is worth enough needs signing off before it moves.
 *
 * A transfer is the second document that can take stock out of a warehouse
 * without a customer: it leaves the building. The two-leg lifecycle (dispatch →
 * receive) is untouched — approval clears the gate *before* dispatch, so an
 * unapproved transfer is a draft that cannot be dispatched, and "in transit"
 * always means somebody approved it.
 *
 * `total_value` stores the figure the threshold judged, so the reason a transfer
 * needed approval stays readable after the setting changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->decimal('total_value', 18, 4)->default(0)->after('narration');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('dispatched_at');
            $table->string('approval_note', 500)->nullable()->after('approved_at');

            $table->index(['company_id', 'status', 'created_at'], 'stock_transfers_company_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex('stock_transfers_company_status_created_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['total_value', 'approved_at', 'approval_note']);
        });
    }
};

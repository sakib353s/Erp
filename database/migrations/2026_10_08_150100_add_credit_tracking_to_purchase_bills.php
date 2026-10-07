<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill can be closed by money (a payment) or by goods coming back (a return
 * with its debit note). Keeping the credited part in its own column means
 * "paid" still means cash and `due_amount` keeps its single definition:
 * total − paid − credited. Without it the bill's due and the AP control
 * account would disagree the moment a return was raised against a bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->decimal('credited_amount', 18, 4)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payments (§03.7) reuse the accounting `payments` table rather than
 * inventing a second money table: a payment is money, whichever direction it
 * flows, and `payment_allocations` is already polymorphic. All this adds is the
 * party on the buy side and an index for the history screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('suppliers')
                ->nullOnDelete();

            $table->index(['company_id', 'supplier_id', 'paid_at'], 'payments_supplier_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_supplier_date_index');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};

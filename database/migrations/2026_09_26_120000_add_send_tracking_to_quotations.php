<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation delivery tracking (02-67): "sent" is only ever shown together
 * with when/where it went, so the status is never a bare claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->string('sent_channel', 16)->nullable()->after('sent_at');
            $table->string('sent_to', 191)->nullable()->after('sent_channel');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_channel', 'sent_to']);
        });
    }
};

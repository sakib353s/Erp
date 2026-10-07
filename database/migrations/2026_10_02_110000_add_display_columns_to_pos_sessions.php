<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-43 POS Customer Display: paired channel between the till and the
 * customer-facing screen — the session carries a short pairing code plus
 * a whitelisted cart snapshot (items/total only, never drawer money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->string('display_code', 16)->nullable()->after('session_no');
            $table->json('display_state')->nullable();
            $table->timestamp('display_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropColumn(['display_code', 'display_state', 'display_updated_at']);
        });
    }
};

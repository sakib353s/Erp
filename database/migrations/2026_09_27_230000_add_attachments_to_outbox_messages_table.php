<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 02-12: email messages carry the documents actually attached (json list of document ids). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->json('attachments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });
    }
};

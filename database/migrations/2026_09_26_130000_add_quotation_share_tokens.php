<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation share links + the public access log behind "viewed" (02-68):
 * a customer opens the link without logging in, the visit is logged, and
 * the status only moves to viewed because of a real logged access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('sent_to');
            $table->timestamp('viewed_at')->nullable()->after('share_token');
        });

        Schema::create('public_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('access_token', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('accessed_at');
            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_access_logs');

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'viewed_at']);
        });
    }
};

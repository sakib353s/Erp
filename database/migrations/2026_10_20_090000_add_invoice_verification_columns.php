<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16-19/§16-20 — the invoice verification link.
 *
 * `invoices.qr_token_hash` was already on the sales table (a nullable 64-char
 * column) so the published half of the feature had somewhere to live. These
 * three columns are what makes it a *life cycle* rather than a string: which
 * rotation the published link is on, when it was published, and when it was
 * withdrawn. Nothing on this row is the token itself — only its SHA-256 — so a
 * read-only leak of the invoices table hands nobody a working capability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('qr_token_version')->default(0)->after('qr_token_hash');
            $table->timestamp('qr_token_issued_at')->nullable()->after('qr_token_version');
            $table->timestamp('qr_token_revoked_at')->nullable()->after('qr_token_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['qr_token_version', 'qr_token_issued_at', 'qr_token_revoked_at']);
        });
    }
};

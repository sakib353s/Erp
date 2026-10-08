<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16-21 — the public document link.
 *
 * The `documents` table was built with `public_token_hash`,
 * `public_token_expires_at` and `public_token_revoked_at` on it (section K) but
 * nothing ever wrote them. These two columns are what turn a stored hash into a
 * link with a life cycle: which rotation the published address is on, and when
 * it was published. As with the invoice verification link, the row holds the
 * SHA-256 of the address and never the address itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('public_token_version')->default(0)->after('public_token_hash');
            $table->timestamp('public_token_issued_at')->nullable()->after('public_token_version');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['public_token_version', 'public_token_issued_at']);
        });
    }
};

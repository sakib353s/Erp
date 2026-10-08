<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15 — what maintenance actually did.
 *
 * A maintenance screen is the one place in an ERP where a button can quietly
 * remove something somebody needed: compiled views are harmless, an upload
 * directory is not. Two things follow, and this table is both of them.
 *
 *  · **Every operation leaves a record.** Not only an audit event — which says
 *    *that* somebody cleared the cache — but the figures: how many files, how
 *    many bytes, which tables were checked and what they answered. The button
 *    and the consequence are recorded together, so “the log viewer was slow
 *    after yesterday's maintenance” is answerable.
 *  · **The record is per company, and it is what later operations read.** The
 *    repair operation refuses to touch a table the last integrity check did not
 *    name — a rule that only exists if the last check is on file. So the table
 *    is not a log of the past; it is the state the desk stands on.
 *
 * `status` is `ok`, `refused` or `failed`, and `refused` is a real outcome: an
 * operation that correctly declined (no backup to restore from, a table the
 * check never named) has to be visible as a decision rather than an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // cache.clear · sessions.clear · temp.clear · db.optimize ·
            // db.integrity · db.repair · settings.reset · self.heal ·
            // search.rebuild
            $table->string('action', 64)->index();
            $table->string('status', 16);

            // One sentence a person can read, and the figures behind it.
            $table->string('summary', 500)->nullable();
            $table->json('details')->nullable();
            $table->unsignedBigInteger('freed_bytes')->default(0);

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};

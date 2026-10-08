<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-03/04/09/10 — the registers behind the company's identity and its
 * compliance calendar: trade licence, TIN & BIN, certificates, contracts,
 * agreements, insurance, RJSC filings, statutory obligations, brand assets.
 *
 * These look like nine features in the menu tree and are one thing in the
 * database: a *record* with a kind. The alternative — nine tables — would mean
 * nine nearly identical screens, nine places to fix a date-format bug, and a
 * renewal reminder that only knows how to see licences. A trade licence and an
 * insurance policy differ in what they are called and what they hold, not in how
 * they behave: both are issued by somebody, carry a number, run out on a date,
 * and get renewed. A filing and a statutory obligation are the same record with
 * a deadline instead of an expiry: they repeat, and finishing one rolls the next
 * one forward.
 *
 * Nothing here stores a file. Papers live in `documents` (safe MIME pipeline,
 * checksums, audited downloads) and a record *attaches* them, which is why the
 * seal image is not a second copy of the seal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            /* A licence for the Dhanmondi outlet belongs to that branch; the
               company's incorporation certificate belongs to no branch at all,
               and `null` says exactly that. */
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 32); // licence|tax_id|certificate|contract|agreement|brand_asset|insurance|filing|obligation
            $table->string('title', 191);
            $table->string('reference_no', 120)->nullable();

            /* Who stands behind the paper: NBR, RJSC, DESCO, the insurer, the
               counterparty a contract is signed with. */
            $table->string('issuer', 160)->nullable();

            /* The other side of a contract or an agreement. Kept apart from
               `issuer` because "who issued this licence" and "who we signed
               this with" are different questions that only look alike. */
            $table->string('counterparty', 160)->nullable();

            $table->decimal('value_amount', 15, 2)->nullable();

            $table->date('issued_on')->nullable();
            $table->date('starts_on')->nullable();

            /* When it stops being true. A TIN does not expire, so this stays
               empty and the register says "no expiry on file" rather than
               inventing one. */
            $table->date('expires_on')->nullable();

            /* The deadline half: filings and statutory obligations. */
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('repeat_months')->nullable();
            $table->date('last_completed_on')->nullable();

            $table->string('status', 16)->default('active'); // active|retired
            $table->date('retired_on')->nullable();

            $table->text('notes')->nullable();
            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'kind', 'status']);
            $table->index(['company_id', 'expires_on']);
            $table->index(['company_id', 'due_on']);
        });

        Schema::create('business_record_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same scan on the same record twice is a duplicate, not a history.
            $table->unique(['business_record_id', 'document_id']);
        });

        Schema::create('business_record_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_record_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32); // created|updated|renewed|completed|attached|detached|retired
            $table->date('happened_on');
            $table->string('note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_record_id', 'happened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_record_events');
        Schema::dropIfExists('business_record_files');
        Schema::dropIfExists('business_records');
    }
};

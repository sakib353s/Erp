<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document foundation (Rules 8/9/10, spec section L):
 * - document_types: registry where the normal sales document prints as
 *   "INVOICE" and Mushak 9.1/11 remain SEPARATE statutory types;
 * - documents: safe file metadata (generated names, sniffed MIME,
 *   checksum, private/public split, hashed public tokens);
 * - print_history: print/download auditing;
 * - numbering_rules + numbering_sequences: DB-driven document numbers
 *   with row-locked allocation (no controller hard-coding).
 */
return new class extends Migration
{
    public function up(): void
 {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();          // invoice|mushak_9_1|mushak_11...
            $table->string('type_group', 32);              // sales|purchase|hr|accounting|statutory|report
            $table->string('name', 96);
            $table->string('printed_title', 96);           // "INVOICE" for sales invoices (Rule 8)
            $table->string('default_template', 48)->default('default');
            $table->char('language', 5)->default('en');
            $table->boolean('is_statutory')->default(false);
            $table->boolean('tax_applicable')->default(false); // tax shows ONLY when configured/applicable
            $table->boolean('requires_numbering')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('owner_type', 96)->nullable();   // polymorphic attach (Company, User, later Invoice...)
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->string('purpose', 48)->nullable();      // logo|seal|signature|attachment|generated|export

            $table->string('visibility', 16)->default('private'); // private|public
            $table->char('public_token_hash', 64)->nullable()->unique(); // sha256(opaque token)
            $table->timestamp('public_token_expires_at')->nullable();
            $table->timestamp('public_token_revoked_at')->nullable();

            $table->string('disk', 16)->default('local');   // local (private) | public
            $table->string('path', 255);                    // generated safe path
            $table->string('original_name', 191);           // sanitised display name
            $table->string('mime_type', 127);               // sniffed via fileinfo
            $table->char('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);                   // sha256 of content
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('derivative_path', 255)->nullable(); // generated WebP

            $table->unsignedInteger('version')->default(1);
            $table->foreignId('revision_of_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->string('scan_status', 16)->default('not_scanned'); // not_scanned|clean|suspicious (truthful)
            $table->timestamp('scanned_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['company_id', 'purpose']);
            $table->index(['document_type_id']);
            $table->index(['checksum']);
        });

        Schema::create('print_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->string('printable_type', 96);
            $table->unsignedBigInteger('printable_id');
            $table->string('format', 8);                   // html|pdf
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('correlation_id', 36)->nullable();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['printable_type', 'printable_id']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('numbering_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->default(0); // 0 = company-wide (sentinel, Settings-style)
            $table->string('prefix', 32);
            $table->string('pattern', 96);                 // {PREFIX}/{YYYY}/{SEQ}
            $table->unsignedSmallInteger('padding')->default(5);
            $table->string('reset_period', 8)->default('none'); // none|yearly|monthly
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'document_type_id', 'branch_id']);
        });

        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('numbering_rule_id')->constrained('numbering_rules')->cascadeOnDelete();
            $table->string('period_key', 16)->default('');  // '' | '2026' | '2026-09'
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['numbering_rule_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
        Schema::dropIfExists('numbering_rules');
        Schema::dropIfExists('print_history');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};

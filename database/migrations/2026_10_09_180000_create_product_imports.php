<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §04-12 — the record of what a catalogue import actually did.
 *
 * A bulk write deserves a receipt. Somebody uploads four hundred rows, sees
 * "312 created, 5 updated, 3 failed", closes the tab — and three weeks later
 * asks why a cost is wrong. Without this table the only answer is a shrug.
 *
 * A dry run is recorded too (`dry_run = true`, `status = preview`): it writes
 * nothing but it is still a statement about the file, and the error list is the
 * reason somebody went back and fixed their spreadsheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 191);
            $table->string('status', 32);            // preview | imported | imported_with_errors
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('headers')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'product_imports_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};

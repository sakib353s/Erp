<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global search index (decision D17): normalized rows rebuilt from
 * source tables; permission and branch filters are applied at query
 * time in SearchService, never baked into the index alone.
 *
 * MySQL gets a FULLTEXT index on (title, normalized); SQLite (tests)
 * relies on the composite indexes + LIKE predicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 96);           // user|branch|warehouse|role|document|...
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('branch_id')->nullable(); // null = company-wide row
            $table->string('permission_key', 128)->nullable();  // required key to see this hit
            $table->string('title', 191);
            $table->string('subtitle', 191)->nullable();
            $table->string('excerpt', 500)->nullable();
            $table->string('url', 255);
            $table->string('normalized', 500);           // lowercased searchable blob
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['company_id', 'entity_type']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['permission_key']);
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE search_index ADD FULLTEXT search_index_fulltext (title, normalized)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index');
    }
};

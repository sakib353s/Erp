<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 02-13: queued, scope-filtered order exports producing a CSV document. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('queued'); // queued|processing|completed|failed
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_exports');
    }
};

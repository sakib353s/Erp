<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-28 Fake / Suspicious orders: one flag row per order the scorer puts
 * at or above the threshold. The row carries the score, the rule
 * explainability snapshot and the human review decision. Reviews never
 * delete or mutate the order itself; a reviewed flag keeps its score
 * snapshot forever (evidence is never auto-deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspicious_order_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('level', 16); // elevated | high
            $table->json('rules'); // [{key,label,weight,evidence}] explainability snapshot
            $table->string('status', 24)->default('open'); // open | reviewed
            $table->string('decision', 32)->nullable(); // confirmed_legitimate | confirmed_suspicious
            $table->string('review_notes', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('scored_at');
            $table->timestamps();

            $table->unique(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicious_order_flags');
    }
};

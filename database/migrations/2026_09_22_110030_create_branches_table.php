<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches — many per company (Rule 1). Every branch-sensitive table in the
 * system references this table; branch access is enforced server-side via
 * user_branch + TenantContext (Rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('code', 32);
            $table->string('name', 191);
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('area', 128)->nullable();
            $table->string('district', 64)->nullable();
            $table->string('postal_code', 16)->nullable();

            $table->string('operating_status', 32)->default('active');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

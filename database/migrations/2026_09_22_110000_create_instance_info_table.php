<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform/instance registration (control-plane architecture, D1/D2/C3).
 * Single row describing THIS isolated ERP instance as provisioned by the
 * platform control plane. The tenant never stores subscription/billing
 * authority here — only its own registration identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_info', function (Blueprint $table) {
            $table->id();
            $table->boolean('singleton')->default(1)->unique();
            $table->string('slug', 64)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('platform_ref', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('schema_version', 32)->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_info');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side branch/warehouse assignment (Rules 4 & 5).
 * A user may be assigned one branch, several branches, or (via
 * users.branch_scope='all') every branch — always resolved server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });

        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_user');
        Schema::dropIfExists('user_branch');
    }
};

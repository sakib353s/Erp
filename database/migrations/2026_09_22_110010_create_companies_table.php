<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE company per operational ERP instance (Rules 1 & 2, decisions D1/D2).
 * The `singleton` UNIQUE column is the DB-level guard that makes a second
 * company row impossible inside a tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('singleton')->default(1)->unique();

            $table->string('name', 191);
            $table->string('legal_name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 191)->nullable();

            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('area', 128)->nullable();
            $table->string('district', 64)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->char('country', 2)->default('BD');

            $table->char('currency', 3)->default('BDT');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->char('locale', 5)->default('en');
            $table->char('alt_locale', 5)->default('bn');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(4); // 1 April (Bangladesh)

            $table->string('trade_license_no', 64)->nullable();
            $table->string('tin', 32)->nullable();
            $table->string('bin', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

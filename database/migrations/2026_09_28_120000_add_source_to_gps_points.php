<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 02-93 gps_points gain a source column so rider positions
 * (source=rider, consent-gated) stay separable from field-visit
 * points (source=field_visit, the historical default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_points', function (Blueprint $table): void {
            $table->string('source', 24)
                ->default('field_visit')
                ->after('field_visit_id');
        });
    }

    public function down(): void
    {
        Schema::table('gps_points', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};

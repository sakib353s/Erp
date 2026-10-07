<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 02-93 rider assignment response workflow: new assignments are
 * pending until the rider accepts or declines. Legacy name-only rows
 * (02-06 bulk assign) predate the flow — they were recorded as
 * finished handoffs, so they are backfilled to accepted with no
 * response history (responded_at/responded_by stay null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_assignments', function (Blueprint $table): void {
            $table->foreignId('rider_employee_id')
                ->nullable()
                ->after('rider_name')
                ->constrained('employees')
                ->nullOnDelete();
            $table->string('status', 16)
                ->default('pending')
                ->after('rider_employee_id'); // pending|accepted|declined
            $table->timestamp('responded_at')->nullable()->after('status');
            $table->foreignId('responded_by')
                ->nullable()
                ->after('responded_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('rider_assignments')
            ->whereNull('rider_employee_id')
            ->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        Schema::table('rider_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn(['responded_at', 'status', 'rider_employee_id']);
        });
    }
};

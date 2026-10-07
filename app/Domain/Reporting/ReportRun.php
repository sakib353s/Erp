<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One executed run of a custom report: exact row count + a snapshot of
 * the produced rows (capped) + honest failure state.
 */
class ReportRun extends Model
{
    protected $fillable = [
        'company_id', 'report_definition_id', 'scheduled_report_id', 'triggered_by',
        'status', 'row_count', 'snapshot', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'row_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    public function scheduledReport(): BelongsTo
    {
        return $this->belongsTo(ScheduledReport::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}

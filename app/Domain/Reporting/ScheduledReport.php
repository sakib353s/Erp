<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReport extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'company_id', 'report_definition_id', 'name', 'frequency',
        'next_run_at', 'last_run_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

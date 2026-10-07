<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved custom report: source + whitelisted column/filter keys only.
 * Columns are re-validated against CustomReportBuilder::SOURCES on every
 * run — scope is enforced at run time, never trusted from storage.
 */
class ReportDefinition extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'source', 'columns', 'filters', 'created_by',
    ];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ScheduledReport::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }
}

<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One materialized daily metric value. Written only by refresh paths
 * (TrendQuery upserts from invoices); never hand-edited.
 */
class BiMetricDaily extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'metric_date', 'metric', 'value',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'value' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

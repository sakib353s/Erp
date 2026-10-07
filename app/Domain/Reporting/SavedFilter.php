<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFilter extends Model
{
    protected $fillable = [
        'company_id', 'report_definition_id', 'name', 'payload', 'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
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

<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS provider master. API keys are stored in encrypted settings — this
 * row only holds non-secret routing metadata.
 */
class SmsProvider extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'api_endpoint', 'sender_id',
        'config_status', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

<?php

namespace App\Domain\Masters;

use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Courier master. Never stores adapter credentials — those live in
 * encrypted settings; credentials_meta holds only non-sensitive hints
 * (label, docs). The webhook signing secret (02-90) is stored through
 * the model's `encrypted` cast, so plaintext never reaches the table.
 */
class Courier extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'service_status',
        'configuration_status', 'credentials_meta', 'integration_enabled',
        'tracking_url_pattern', 'webhook_secret', 'branch_specific',
        'is_active', 'sort',
    ];

    protected $casts = [
        'integration_enabled' => 'boolean',
        'branch_specific' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'webhook_secret' => 'encrypted',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isConfigured(): bool
    {
        return $this->configuration_status === 'configured';
    }
}

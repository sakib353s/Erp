<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class FiscalYear extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'starts_on', 'ends_on',
        'status', 'is_current',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_current' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function contains(Carbon $date): bool
    {
        return $date->between($this->starts_on, $this->ends_on);
    }
}

<?php

namespace App\Domain\Masters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Upazila extends Model
{
    protected $fillable = [
        'district_id', 'code', 'name', 'name_bn', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

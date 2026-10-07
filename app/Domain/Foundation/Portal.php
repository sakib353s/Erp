<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;

class Portal extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

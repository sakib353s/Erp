<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'code', 'name', 'label_key', 'icon', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}

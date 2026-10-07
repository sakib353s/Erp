<?php

namespace App\Domain\Hr\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Department (10-07). Hierarchy is one level deep in practice but the model
 * allows nesting: reporting rolls up through `parent_id`.
 */
class Department extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'parent_id', 'code', 'name', 'cost_center', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

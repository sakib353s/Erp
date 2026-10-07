<?php

namespace App\Domain\Hr\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Job title (10-08), optionally bound to a department. */
class Designation extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'department_id', 'code', 'name', 'grade', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

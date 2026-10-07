<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountGroup extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'parent_id',
        'is_system', 'sort',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_group_id');
    }
}

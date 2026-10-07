<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use Auditable;
    use \App\Domain\Foundation\Concerns\ScopedByBranch;

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name', 'address', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_user')->withTimestamps();
    }

    /** §04-43: the physical structure — zones, and the bins inside them. */
    public function zones(): HasMany
    {
        return $this->hasMany(\App\Domain\Inventory\WarehouseZone::class)->orderBy('sort_order')->orderBy('code');
    }

    public function bins(): HasMany
    {
        return $this->hasMany(\App\Domain\Inventory\WarehouseBin::class);
    }
}

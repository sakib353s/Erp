<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReorderPolicy extends Model
{
    protected $fillable = [
        'company_id', 'product_id', 'warehouse_id',
        'min_level', 'max_level', 'reorder_point', 'safety_stock',
        'reorder_qty', 'lead_time_days', 'is_active',
    ];

    protected $casts = [
        'min_level' => 'decimal:4',
        'max_level' => 'decimal:4',
        'reorder_point' => 'decimal:4',
        'safety_stock' => 'decimal:4',
        'reorder_qty' => 'decimal:4',
        'lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

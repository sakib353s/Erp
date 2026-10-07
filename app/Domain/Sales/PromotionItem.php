<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionItem extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'promotion_id', 'product_id', 'min_qty',
    ];

    protected $casts = [
        'min_qty' => 'integer',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

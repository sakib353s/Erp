<?php

namespace App\Domain\Masters;

use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    protected $fillable = ['price_list_id', 'product_id', 'price'];

    protected $casts = [
        'price' => 'decimal:4',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

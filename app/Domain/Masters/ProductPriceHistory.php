<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only price change line (02-109 writes, 02-110 reads). Never
 * updated after insert — corrections append a new line.
 */
class ProductPriceHistory extends Model
{
    /** Singular table name — the 02-109 migration (and live DB) use `product_price_history`. */
    protected $table = 'product_price_history';

    protected $fillable = [
        'company_id', 'product_id', 'price_list_id', 'price_list_item_id',
        'old_price', 'new_price', 'percent_change', 'source',
        'price_bulk_update_id', 'changed_by', 'note',
    ];

    protected $casts = [
        'old_price' => 'decimal:4',
        'new_price' => 'decimal:4',
        'percent_change' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function bulkUpdate(): BelongsTo
    {
        return $this->belongsTo(PriceBulkUpdate::class, 'price_bulk_update_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

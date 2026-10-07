<?php

namespace App\Domain\Customers\Models;

use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Product watch-list for a customer (05-18). */
class CustomerWishlist extends Model
{
    protected $fillable = ['company_id', 'customer_id', 'product_id', 'note'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §16-16 — the promise, per product.
 *
 * A product with no row here is a product nobody promised anything about. That
 * matters more than it sounds: a default of "twelve months" invented by the
 * software is a promise the company never made, and the first customer who
 * quotes it back is a customer the company has to either honour or refuse. So
 * there is no default — the desk says "no policy" and activation does nothing.
 */
class ProductWarranty extends Model
{
    use Auditable;

    public const KINDS = [
        'manufacturer' => 'Manufacturer warranty',
        'seller' => 'Seller warranty',
        'service' => 'Service contract',
    ];

    /** The window beyond which a month count is more likely a typo than a promise. */
    public const MAX_MONTHS = 600;

    protected $fillable = [
        'company_id', 'product_id', 'months', 'kind',
        'covers_parts', 'covers_labour', 'terms', 'is_active', 'updated_by',
    ];

    protected $casts = [
        'months' => 'integer',
        'covers_parts' => 'boolean',
        'covers_labour' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? (string) $this->kind;
    }

    /** What the cover includes, in words the printed terms can use. */
    public function coverLabel(): string
    {
        return match (true) {
            $this->covers_parts && $this->covers_labour => 'Parts and labour',
            $this->covers_parts => 'Parts only',
            $this->covers_labour => 'Labour only',
            default => 'Neither parts nor labour — recorded for the record only',
        };
    }
}

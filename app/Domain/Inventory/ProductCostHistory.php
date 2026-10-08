<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to what a product *says* it costs (§04-10).
 *
 * The valuation layers answer what the goods actually cost; this answers who
 * changed the product record, from what to what, when and why. A cost figure
 * that moved without a row here did not move — it was either never set, or the
 * stock brought it in (which is the ledger's business, not this table's).
 *
 * Append-only: rows are written, read and never rewritten.
 */
class ProductCostHistory extends Model
{
    /**
     * The migration creates the singular `product_cost_history` — the table is
     * named after the record it holds, one row per change. Eloquent would
     * pluralise the class name and miss it, which is a schema that exists and a
     * screen that says it does not.
     */
    protected $table = 'product_cost_history';

    /**
     * Append-only: the table carries `changed_at` (the moment the cost moved)
     * and no `created_at`/`updated_at`, because a row here is a fact that
     * happened, not a record that gets maintained.
     */
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'product_id', 'changed_by',
        'old_standard_cost', 'new_standard_cost',
        'old_cost_method', 'new_cost_method',
        'reason', 'changed_at',
    ];

    protected $casts = [
        'old_standard_cost' => 'decimal:4',
        'new_standard_cost' => 'decimal:4',
        'changed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** @param  Builder<ProductCostHistory>  $query */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /** What changed, in the words the screen prints. */
    public function summary(): string
    {
        $parts = [];

        if ($this->old_standard_cost !== null && (float) $this->old_standard_cost !== (float) $this->new_standard_cost) {
            $parts[] = 'standard cost '.number_format((float) $this->old_standard_cost, 4)
                .' → '.number_format((float) $this->new_standard_cost, 4);
        } elseif ($this->old_standard_cost === null) {
            $parts[] = 'standard cost set to '.number_format((float) $this->new_standard_cost, 4);
        }

        if ($this->old_cost_method !== $this->new_cost_method) {
            $parts[] = 'cost method '.strtoupper((string) $this->old_cost_method)
                .' → '.strtoupper((string) $this->new_cost_method);
        }

        return $parts === [] ? 'Recorded' : implode(' · ', $parts);
    }
}

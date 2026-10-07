<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated pricing rule (02-111). Resolution is a fixed cascade:
 * customer_group → quantity_break → geographic → time_based → special.
 * The architecture chain (base list → group → qty → geographic →
 * time-based → promo/coupon → manual override) is preserved and the
 * per-customer "special price" sits at the top of the unit-price stages,
 * so only an authorized manual override can beat it. Within one stage
 * the lowest priority number wins (ties → lowest id); an absolute
 * `price` replaces the running price, `percent_off` discounts it.
 * Scope columns are nullable = "applies to everything".
 */
class PricingRule extends Model
{
    use Auditable;

    public const TYPE_SPECIAL = 'special';

    public const TYPE_CUSTOMER_GROUP = 'customer_group';

    public const TYPE_QUANTITY_BREAK = 'quantity_break';

    public const TYPE_GEOGRAPHIC = 'geographic';

    public const TYPE_TIME_BASED = 'time_based';

    /** Stage order = resolution order (weakest → strongest authority). */
    public const STAGES = [
        self::TYPE_CUSTOMER_GROUP,
        self::TYPE_QUANTITY_BREAK,
        self::TYPE_GEOGRAPHIC,
        self::TYPE_TIME_BASED,
        self::TYPE_SPECIAL,
    ];

    public const TYPES = [
        self::TYPE_SPECIAL,
        self::TYPE_CUSTOMER_GROUP,
        self::TYPE_QUANTITY_BREAK,
        self::TYPE_GEOGRAPHIC,
        self::TYPE_TIME_BASED,
    ];

    /** Approval-workflow identity for manual override requests (02-113). */
    public const ENTITY_TYPE = 'pricing_rule';

    public const APPROVAL_ACTION = 'override';

    /**
     * Manual override threshold (02-113): an override goes deeper than
     * this percent below the base price (fixed price) or grants more
     * than this percent off — without pricing.override it needs WF.
     */
    public const OVERRIDE_THRESHOLD_PCT = 25.0;

    protected $fillable = [
        'company_id', 'rule_type', 'name', 'priority', 'is_active', 'approval_status',
        'price_list_id', 'product_id', 'product_category_id',
        'customer_id', 'customer_group_id', 'delivery_zone_id',
        'qty_min', 'qty_max', 'price', 'percent_off',
        'valid_from', 'valid_to', 'time_from', 'time_to',
        'created_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'price' => 'decimal:4',
        'percent_off' => 'decimal:4',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /** Human-readable scope line for the compare screen (02-112). */
    public function scopeDescription(): string
    {
        $parts = match ($this->rule_type) {
            self::TYPE_SPECIAL => ['customer '.($this->customer?->code ?? '#'.$this->customer_id)],
            self::TYPE_CUSTOMER_GROUP => ['group '.($this->customerGroup?->code ?? '#'.$this->customer_group_id)],
            self::TYPE_QUANTITY_BREAK => ['qty '.($this->qty_min ?? 1).'–'.($this->qty_max ?? 'any')],
            self::TYPE_GEOGRAPHIC => ['zone '.($this->deliveryZone?->code ?? '#'.$this->delivery_zone_id)],
            default => [],
        };

        if ($this->product !== null) {
            $parts[] = 'product '.$this->product->code;
        }
        if ($this->product_category_id !== null) {
            $parts[] = 'category #'.$this->product_category_id;
        }
        if ($this->priceList !== null) {
            $parts[] = 'list '.$this->priceList->code;
        }

        $window = [];
        if ($this->valid_from !== null || $this->valid_to !== null) {
            $window[] = ($this->valid_from?->toDateString() ?? '…').' → '.($this->valid_to?->toDateString() ?? '…');
        }
        if ($this->time_from !== null || $this->time_to !== null) {
            $window[] = ($this->time_from ?? '00:00').'–'.($this->time_to ?? '24:00');
        }

        $description = $parts === [] ? 'everywhere' : implode(' · ', $parts);
        if ($window !== []) {
            $description .= ' ('.implode(', ', $window).')';
        }

        return $description;
    }

    /** "৳85.00 fixed" / "10% off" for the compare screen (02-112). */
    public function valueDescription(): string
    {
        return $this->price !== null
            ? number_format((float) $this->price, 2).' fixed'
            : '−'.number_format((float) $this->percent_off, 2).'%';
    }

    /** The price this rule alone would produce from the given base (02-112). */
    public function applyTo(float $base): float
    {
        return $this->price !== null
            ? round((float) $this->price, 4)
            : round($base * (1 - ((float) $this->percent_off) / 100), 4);
    }

    /** True while a manual override sits in the approval queue (02-113). */
    public function hasPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Does this payload push a value past the override threshold?
     * percent_off is inherently relative; a fixed price is compared to
     * the base (default list item, falling back to standard cost) of its
     * product — unscoped fixed prices have no base to undercut.
     *
     * @param  array<string, mixed>  $data
     */
    public static function exceedsOverrideThreshold(array $data, int $companyId, ?self $existing = null): bool
    {
        $threshold = self::OVERRIDE_THRESHOLD_PCT;

        if (($data['percent_off'] ?? null) !== null) {
            return (float) $data['percent_off'] > $threshold;
        }

        if (($data['price'] ?? null) === null) {
            return false;
        }

        $productId = ($data['product_id'] ?? null) ?? $existing?->product_id;

        if ($productId === null) {
            return false;
        }

        $base = PriceListItem::query()
            ->where('product_id', (int) $productId)
            ->whereIn('price_list_id', PriceList::query()
                ->where('company_id', $companyId)
                ->where('is_default', true)
                ->select('id'))
            ->value('price');

        if ($base === null) {
            $base = Product::query()->where('id', (int) $productId)->value('standard_cost');
        }

        $base = (float) $base;

        if ($base <= 0) {
            return false;
        }

        return (float) $data['price'] < $base * (1 - $threshold / 100);
    }
}

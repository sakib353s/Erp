<?php

namespace App\Domain\Masters;

use App\Domain\Customers\Models\CustomerAddress;
use App\Domain\Customers\Models\CustomerContact;
use App\Domain\Customers\Models\CustomerFeedback;
use App\Domain\Customers\Models\CustomerReferral;
use App\Domain\Customers\Models\CustomerWishlist;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer / party master (§05 CRM + §14 masters).
 *
 * Balance truth is never stored here: dues are derived from issued invoices
 * minus posted payment allocations (see CustomerQuery::openInvoiceQuery()).
 * `opening_balance` is the only carried figure, and it is the counterpart of
 * the opening journal entry posted at go-live.
 */
class Customer extends Model
{
    use Auditable;

    public const TYPES = ['individual', 'business'];
    public const SEGMENTS = ['new', 'regular', 'vip', 'at_risk'];

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'phone', 'alt_phone', 'email', 'bin',
        'tax_vat_no', 'contact_person', 'district_id', 'address_line1', 'notes',
        'credit_limit', 'credit_days', 'opening_balance', 'opening_balance_type',
        'is_active', 'is_blacklisted', 'blacklist_reason', 'blacklisted_by',
        'blacklisted_at', 'loyalty_points', 'segment', 'customer_group_id',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:4',
        'credit_days' => 'integer',
        'loyalty_points' => 'integer',
        'is_active' => 'boolean',
        'is_blacklisted' => 'boolean',
        'blacklisted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(CustomerFeedback::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(CustomerReferral::class);
    }

    public function wishlist(): HasMany
    {
        return $this->hasMany(CustomerWishlist::class);
    }

    public function defaultAddress(): ?CustomerAddress
    {
        return $this->addresses->firstWhere('is_default', true) ?? $this->addresses->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Customers that may transact: active and not blacklisted. */
    public function scopeOrderable($query)
    {
        return $query->where('is_active', true)->where('is_blacklisted', false);
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    public function creditPosition(): array
    {
        return [
            'limit' => (float) $this->credit_limit,
            'credit_days' => (int) $this->credit_days,
            'blacklisted' => (bool) $this->is_blacklisted,
        ];
    }

    public function status(): string
    {
        if ($this->is_blacklisted) {
            return 'blacklisted';
        }

        return $this->is_active ? 'active' : 'inactive';
    }
}

<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier party (§06). Deepened for purchasing: contact, terms, TIN/BIN,
 * bank destination and a blacklist that requires a reason — a supplier is
 * never silently barred.
 */
class Supplier extends Model
{
    use Auditable;

    public const CATEGORIES = ['goods', 'service', 'transport', 'utility', 'other'];

    protected $fillable = [
        'company_id', 'code', 'name', 'contact_person', 'phone', 'phone_alt', 'email',
        'category', 'bin', 'tin', 'payment_terms_days', 'bank_name', 'bank_account_no',
        'mobile_wallet', 'credit_limit', 'notes', 'district_id', 'address_line1',
        'is_active', 'is_blacklisted', 'blacklist_reason', 'blacklisted_at', 'blacklisted_by',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_blacklisted' => 'boolean',
        'blacklisted_at' => 'datetime',
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function blacklistedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\User::class, 'blacklisted_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Suppliers an order may actually be raised against. */
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
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('bin', 'like', "%{$term}%");
        });
    }

    public function status(): string
    {
        if ($this->is_blacklisted) {
            return 'blacklisted';
        }

        return $this->is_active ? 'active' : 'inactive';
    }

    /** Throws when a document must not be raised against this supplier. */
    public function assertOrderable(): void
    {
        if ($this->is_blacklisted) {
            throw new \RuntimeException(sprintf(
                '%s is blacklisted (%s). Lift the blacklist before raising documents.',
                $this->name,
                $this->blacklist_reason ?: 'no reason recorded',
            ));
        }

        if (! $this->is_active) {
            throw new \RuntimeException(sprintf('%s is inactive and cannot receive new documents.', $this->name));
        }
    }
}

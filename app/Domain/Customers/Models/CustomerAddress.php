<?php

namespace App\Domain\Customers\Models;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Masters\Upazila;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery / billing address for a customer (05-06).
 *
 * A customer may hold many addresses but only one default per purpose; the
 * service layer enforces that flag transactionally.
 */
class CustomerAddress extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'customer_id', 'label', 'contact_name', 'contact_phone',
        'address_line1', 'address_line2', 'district_id', 'upazila_id',
        'postcode', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }

    public function oneLine(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            $this->upazila?->name,
            $this->district?->name,
            $this->postcode,
        ])->filter()->implode(', ');
    }
}

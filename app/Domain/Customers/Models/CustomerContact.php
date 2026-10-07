<?php

namespace App\Domain\Customers\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Named contact person at a customer (05-02/05-05). */
class CustomerContact extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'customer_id', 'name', 'designation', 'phone', 'email', 'is_primary',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

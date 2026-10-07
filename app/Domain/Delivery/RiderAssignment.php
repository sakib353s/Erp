<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rider attached to a shipment. Pre-02-93 rows carry a free-text
 * rider_name only (02-06 bulk assign, backfilled status=accepted);
 * roster assignments link the employee and run the response flow:
 * pending → accepted / declined by the rider (or on their behalf by
 * a sales.delivery.riders holder).
 */
class RiderAssignment extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'company_id', 'shipment_id', 'rider_name', 'rider_employee_id',
        'status', 'responded_at', 'responded_by', 'assigned_by',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_employee_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** True while the assignment still awaits the rider's response. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}

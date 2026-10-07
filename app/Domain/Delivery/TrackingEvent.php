<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Courier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One courier tracking observation (02-90). `source` is always
 * truthful: `manual` rows carry the operator who recorded them,
 * `webhook` rows carry the courier's external event id and an
 * idempotency key so a redelivered webhook never duplicates. The event
 * log never fabricates status — shipment/order statuses only move when
 * a validated transition exists.
 */
class TrackingEvent extends Model
{
    use Auditable;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_WEBHOOK = 'webhook';

    protected $fillable = [
        'company_id', 'shipment_id', 'courier_id', 'event_code',
        'description', 'location', 'occurred_at', 'source',
        'external_event_id', 'idempotency_key', 'actor_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

<?php

namespace App\Domain\Outbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Foundation\Company;

/**
 * Outbox row (decision D13): truthful lifecycle only —
 * pending → processing → dispatched | failed | discarded.
 */
class OutboxEvent extends Model
{
    protected $fillable = [
        'company_id', 'aggregate_type', 'aggregate_id', 'event_type', 'payload',
        'status', 'attempts', 'max_attempts', 'available_at', 'dispatched_at',
        'last_error', 'idempotency_key',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'pending' && $this->available_at->lte(now());
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->max_attempts;
    }
}

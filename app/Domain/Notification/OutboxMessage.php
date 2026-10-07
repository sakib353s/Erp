<?php

namespace App\Domain\Notification;

use App\Domain\Foundation\Company;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A queued customer message (02-10…02-12). Truthful states only:
 * `not_configured` = no active SMS provider with real credentials (the
 * message is HELD, never claimed sent), `queued` = sitting in the
 * outbox awaiting the delivery worker, `sent`/`failed` are only ever
 * written by an actual delivery attempt.
 */
class OutboxMessage extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'channel', 'message_template_id', 'sales_order_id',
        'recipient', 'subject', 'body', 'attachments', 'status', 'provider_code',
        'provider_ref', 'last_error', 'queued_at', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(DeliveryLog::class);
    }
}

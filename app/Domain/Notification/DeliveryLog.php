<?php

namespace App\Domain\Notification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery attempt per message state change (Rule: history is append-only). */
class DeliveryLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'outbox_message_id', 'attempt', 'status',
        'response_code', 'response_body', 'error',
    ];

    protected $casts = ['attempt' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}

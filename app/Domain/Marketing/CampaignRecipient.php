<?php

namespace App\Domain\Marketing;

use App\Domain\Masters\Customer;
use App\Domain\Notification\OutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §11 — one customer the campaign was aimed at, as they were on the day.
 *
 * The row is deliberately thin: the name and contact are copied because a
 * campaign report has to say who was written to *then*, even after somebody
 * changes their phone number, and the cost is frozen because next month's price
 * must not rewrite what last month's send cost.
 *
 * What happened to the message is **not** stored here. If the row points at an
 * outbox message, the delivery state is read from that message (`queued`,
 * `not_configured`, `sent`, `failed`) — the shared outbox is the only place a
 * message can be marked sent, so the campaign report cannot claim a delivery the
 * outbox does not know about. A row with no outbox message was never handed to a
 * transport at all, and `skip_reason` says why.
 */
class CampaignRecipient extends Model
{
    /** Named for what it is; the table it lives on is the campaign's. */
    protected $table = 'marketing_campaign_recipients';

    public const SKIP_OPTOUT = 'opted_out';

    public const SKIP_NO_ADDRESS = 'no_address';

    public const SKIP_BLACKLISTED = 'blacklisted';

    protected $fillable = [
        'company_id', 'campaign_id', 'customer_id', 'name', 'contact',
        'skip_reason', 'outbox_message_id', 'cost',
    ];

    protected $casts = [
        'cost' => 'decimal:4',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }

    /* ------------------------------------------------------------------ state */

    public function isSkipped(): bool
    {
        return $this->outbox_message_id === null;
    }

    /** The one word the report shows, read from the outbox wherever possible. */
    public function state(): string
    {
        if ($this->outbox_message_id === null) {
            return $this->skip_reason ?? 'skipped';
        }

        return (string) ($this->message?->status ?? 'queued');
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            'queued' => 'Queued',
            'not_configured' => 'Held',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            self::SKIP_OPTOUT => 'Opted out',
            self::SKIP_NO_ADDRESS => 'No address',
            self::SKIP_BLACKLISTED => 'Blacklisted',
            default => 'Skipped',
        };
    }

    /** Handed to a transport — held or queued, either way it costs money. */
    public function wasHandedOver(): bool
    {
        return $this->outbox_message_id !== null;
    }

    public function skipLabel(): string
    {
        $field = $this->campaign?->contactField() ?? 'contact';
        $channel = $this->campaign?->channelLabel() ?? 'this channel';

        return match ($this->skip_reason) {
            self::SKIP_OPTOUT => "They asked not to be written to by {$channel}.",
            self::SKIP_NO_ADDRESS => "No {$field} on file for {$channel}.",
            self::SKIP_BLACKLISTED => 'The customer is on the blacklist, so nothing is marketed to them.',
            default => 'Skipped.',
        };
    }
}

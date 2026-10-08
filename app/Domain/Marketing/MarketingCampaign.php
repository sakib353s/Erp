<?php

namespace App\Domain\Marketing;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §11 — one campaign: a channel, a message, an audience rule and a decision.
 *
 * A campaign is a *draft* until somebody launches it, and launching is the
 * moment the audience is actually expanded and the messages are handed to the
 * outbox. That is why there is no “sent” flag here and no recipient counters:
 * the recipients are rows, and what happened to each of them is read from the
 * outbox message the row points at.
 *
 * The audience is stored as a **rule** rather than a list on purpose. “Everybody
 * who bought in the last month” has to mean that on the day it goes out, not on
 * the day somebody typed it — which is exactly what the win-back and birthday
 * style campaigns need. A hand-picked list is the `manual` rule, and even that
 * is expanded at launch so a customer who opted out in the meantime is skipped.
 *
 * Nothing about cost or revenue is invented: the message price is the figure the
 * desk agreed with the provider, and “revenue” is the orders the recipients
 * actually placed inside the attribution window afterwards.
 */
class MarketingCampaign extends Model
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_PUSH = 'push';

    /** The four channels, with what each one needs to be deliverable at all. */
    public const CHANNELS = [
        self::CHANNEL_SMS => ['label' => 'SMS', 'icon' => 'bi-chat-dots', 'contact' => 'phone'],
        self::CHANNEL_EMAIL => ['label' => 'Email', 'icon' => 'bi-envelope', 'contact' => 'email'],
        self::CHANNEL_WHATSAPP => ['label' => 'WhatsApp', 'icon' => 'bi-whatsapp', 'contact' => 'phone'],
        self::CHANNEL_PUSH => ['label' => 'Push', 'icon' => 'bi-bell', 'contact' => 'phone'],
    ];

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_RECENT = 'recent_buyers';

    public const AUDIENCE_DORMANT = 'dormant';

    public const AUDIENCE_MANUAL = 'manual';

    /** How the audience rule is written down, and what it means. */
    public const AUDIENCES = [
        self::AUDIENCE_ALL => [
            'label' => 'Everybody',
            'icon' => 'bi-people',
            'blurb' => 'Every active customer, less anybody who has opted out or is blacklisted.',
            'days' => false,
        ],
        self::AUDIENCE_RECENT => [
            'label' => 'Bought recently',
            'icon' => 'bi-bag-check',
            'blurb' => 'Customers with an invoice inside the window — the people already buying.',
            'days' => true,
        ],
        self::AUDIENCE_DORMANT => [
            'label' => 'Gone quiet',
            'icon' => 'bi-moon-stars',
            'blurb' => 'Customers whose last invoice is older than the window — the win-back list.',
            'days' => true,
        ],
        self::AUDIENCE_MANUAL => [
            'label' => 'A hand-picked list',
            'icon' => 'bi-check2-square',
            'blurb' => 'Named customers, chosen on the day the campaign goes out.',
            'days' => false,
        ],
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_LAUNCHED = 'launched';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_LAUNCHED,
        self::STATUS_CANCELLED,
    ];

    /** The window a dated audience rule falls back to. */
    public const DEFAULT_AUDIENCE_DAYS = 90;

    protected $fillable = [
        'company_id', 'code', 'channel', 'name', 'objective', 'template_id', 'subject', 'body',
        'audience', 'audience_days', 'status', 'scheduled_at', 'launched_at', 'cancelled_at',
        'cancel_reason', 'cost_per_message', 'attribution_days', 'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'launched_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'audience_days' => 'integer',
        'attribution_days' => 'integer',
        'cost_per_message' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    /** Everything this campaign handed to a transport. */
    public function messages(): HasMany
    {
        return $this->hasMany(OutboxMessage::class, 'marketing_campaign_id');
    }

    /* ------------------------------------------------------------------ state */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isLaunched(): bool
    {
        return $this->status === self::STATUS_LAUNCHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Still able to be changed: a launch closes the campaign's script. */
    public function isEditable(): bool
    {
        return $this->isDraft() || $this->isScheduled();
    }

    public function stateLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_LAUNCHED => 'Launched',
            default => 'Cancelled',
        };
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel]['label'] ?? ucfirst((string) $this->channel);
    }

    public function channelIcon(): string
    {
        return self::CHANNELS[$this->channel]['icon'] ?? 'bi-megaphone';
    }

    /** Which customer column this channel writes to. */
    public function contactField(): string
    {
        return self::CHANNELS[$this->channel]['contact'] ?? 'phone';
    }

    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience]['label'] ?? ucfirst((string) $this->audience);
    }

    public function audienceBlurb(): string
    {
        return self::AUDIENCES[$this->audience]['blurb'] ?? '';
    }

    public function windowDays(): int
    {
        return (int) ($this->audience_days ?: self::DEFAULT_AUDIENCE_DAYS);
    }

    /** A scheduled campaign that is due: the dispatcher's question. */
    public function isDue(?Carbon $now = null): bool
    {
        return $this->isScheduled()
            && $this->scheduled_at !== null
            && $this->scheduled_at->lte($now ?? Carbon::now());
    }

    public function attributionEndsAt(): ?Carbon
    {
        return $this->launched_at?->copy()->addDays(max(1, (int) $this->attribution_days));
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', ($now ?? Carbon::now())->toDateTimeString());
    }
}

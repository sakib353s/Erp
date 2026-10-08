<?php

namespace App\Domain\Marketing;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §11 — “do not write to me again”, kept as a promise rather than a preference.
 *
 * An opt-out is keyed by the **contact itself** — the phone number or the email
 * address — not by the customer row, because the person who types STOP owns the
 * number whether or not whoever typed it into the customer master was paying
 * attention. It is checked before a campaign hands anything to a transport, and
 * a channel of `all` means “on every channel we have”, which is what somebody
 * writing “unsubscribe from everything” has actually asked for.
 *
 * The row is never deleted quietly: lifting an opt-out is a deliberate action
 * with a name behind it, and the campaign that earned it stays attached.
 */
class MarketingOptout extends Model
{
    public const CHANNEL_ALL = 'all';

    public const CHANNELS = [
        self::CHANNEL_ALL => 'Every channel',
        'sms' => 'SMS',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'push' => 'Push',
    ];

    public const SOURCES = [
        'manual' => 'Entered by the desk',
        'reply' => 'They replied and asked',
        'campaign' => 'Recorded during a campaign',
        'import' => 'Carried in from a list',
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'campaign_id', 'channel', 'contact',
        'reason', 'source', 'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst((string) $this->source);
    }

    /** The channels this entry actually blocks. */
    public function blocks(string $channel): bool
    {
        return $this->channel === self::CHANNEL_ALL || $this->channel === $channel;
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}

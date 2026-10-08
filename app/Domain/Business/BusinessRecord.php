<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-03/04/09/10 — one record in the company's registers.
 *
 * A record is a claim about the world that expires: our trade licence is valid
 * until March, this policy until December, the RJSC return is due in April.
 * Everything the screens need follows from that sentence, which is why the
 * dates are the model's business and the *labels* are the registry's:
 *
 *  · **the tracked date** is `due_on` when there is a deadline (a filing, a duty)
 *    and `expires_on` otherwise (a licence, a policy, a contract). One date per
 *    row is what lets expired licences and missed filings share a calendar.
 *  · **the state** is computed on read — never stored. A licence does not become
 *    expired at midnight because a cron job said so; it becomes expired because
 *    today is past its date. Storing it would mean a nightly job that can fail,
 *    and a register that lies until it runs.
 *  · **retired** is the one status that *is* stored, because it is a decision
 *    (the licence was surrendered, the contract was torn up) rather than an
 *    observation about the clock.
 */
class BusinessRecord extends Model
{
    use Auditable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_RETIRED => 'Retired',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'business_asset_id', 'kind', 'title', 'reference_no', 'issuer',
        'counterparty',
        'value_amount', 'issued_on', 'starts_on', 'expires_on', 'due_on',
        'repeat_months', 'last_completed_on', 'status', 'retired_on', 'notes',
        'meta', 'created_by',
    ];

    protected $casts = [
        'value_amount' => 'decimal:2',
        'issued_on' => 'date',
        'starts_on' => 'date',
        'expires_on' => 'date',
        'due_on' => 'date',
        'repeat_months' => 'integer',
        'last_completed_on' => 'date',
        'retired_on' => 'date',
        'meta' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** §12-14: the asset this paper belongs to, when it belongs to one — a
        vehicle's fitness, a machine's insurance. It stays a certificate either
        way, which is what keeps it on the renewals lens and the calendar. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(BusinessAsset::class, 'business_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(BusinessRecordFile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BusinessRecordEvent::class)->orderByDesc('happened_on')->orderByDesc('id');
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    /** Dated, and the date is inside the window — the "act on this" list. */
    public function scopeTrackedWithin($query, int $days)
    {
        $from = now()->startOfDay();
        $to = now()->startOfDay()->addDays($days);

        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($inner) use ($from, $to) {
                    $inner->whereDate('expires_on', '>=', $from)->whereDate('expires_on', '<=', $to);
                })->orWhere(function ($inner) use ($from, $to) {
                    $inner->whereDate('due_on', '>=', $from)->whereDate('due_on', '<=', $to);
                });
            });
    }

    /** A deadline or an expiry date that has already gone by. */
    public function scopeLapsed($query)
    {
        $today = now()->startOfDay()->toDateString();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('expires_on', '<', $today)->orWhere('due_on', '<', $today));
    }

    /* -------------------------------------------------------------- the clock */

    /** The date this record is judged by: its deadline first, its expiry second. */
    public function trackedOn(): ?CarbonInterface
    {
        return $this->due_on ?? $this->expires_on;
    }

    /**
     * Days until the tracked date, negative once it has passed — null when the
     * record has no date at all (a TIN, a logo). Same reading as a stock batch's
     * days-to-expiry, so "12 days left" means one thing in this application.
     */
    public function daysLeft(): ?int
    {
        $on = $this->trackedOn();

        if ($on === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($on->startOfDay(), false);
    }

    public function isRetired(): bool
    {
        return $this->status === self::STATUS_RETIRED;
    }

    public function isOverdue(): bool
    {
        return ! $this->isRetired() && $this->due_on !== null && $this->due_on->isPast();
    }

    public function isExpired(): bool
    {
        return ! $this->isRetired() && $this->expires_on !== null && $this->expires_on->isPast();
    }

    /**
     * The word the register shows. A retired record says so; otherwise the
     * deadline wins over the expiry (a filing that was due last week is overdue
     * even if its "expiry" is next year), and "no date on file" is a state of its
     * own rather than a quiet kind of valid.
     */
    public function state(int $near = RecordsRegistry::NEAR_DAYS): string
    {
        if ($this->isRetired()) {
            return 'retired';
        }

        $days = $this->daysLeft();

        if ($days === null) {
            return 'undated';
        }

        return match (true) {
            $days < 0 => $this->due_on !== null ? 'overdue' : 'expired',
            $days <= $near => $this->due_on !== null ? 'due_soon' : 'expiring',
            default => 'valid',
        };
    }

    public function stateLabel(int $near = RecordsRegistry::NEAR_DAYS): string
    {
        return RecordsRegistry::STATES[$this->state($near)] ?? $this->state($near);
    }

    /** What the register calls this kind — the registry owns the words. */
    public function kindLabel(): string
    {
        return app(RecordsRegistry::class)->label($this->kind);
    }

    /** A one-line summary for flash messages: "Trade licence — Dhanmondi". */
    public function describe(): string
    {
        return $this->kindLabel().' — '.$this->title;
    }
}

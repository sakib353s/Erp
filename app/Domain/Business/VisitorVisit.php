<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-16 — one visit: a booking that became an arrival, or an arrival on its own.
 *
 * `scheduled_for` is the difference between the two. A row with a date was
 * pre-registered by somebody inside (the host knows they are coming, the gate
 * knows to expect them, the badge is not written out yet); a row without one is
 * a walk-in that was checked in the moment it was recorded.
 *
 * The life cycle is `expected → inside → out`, and the two ways a booking ends
 * without anybody arriving — `cancelled` (somebody said so) and `no_show` (the
 * day passed and nobody did) — are states rather than deletions, because a
 * register that forgets is not a register.
 *
 * How long somebody stayed is never a column: `checked_in_at` and
 * `checked_out_at` are the facts and the minutes are arithmetic between them.
 */
class VisitorVisit extends Model
{
    public const STATUS_EXPECTED = 'expected';

    public const STATUS_INSIDE = 'inside';

    public const STATUS_OUT = 'out';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_EXPECTED,
        self::STATUS_INSIDE,
        self::STATUS_OUT,
        self::STATUS_NO_SHOW,
        self::STATUS_CANCELLED,
    ];

    /** How far ahead the gate keeps a diary — a month, not a year. */
    public const BOOKING_HORIZON_DAYS = 30;

    /** Still inside this long after checking in is worth somebody's attention. */
    public const OVERSTAY_HOURS = 6;

    protected $fillable = [
        'company_id', 'branch_id', 'visitor_id', 'host_user_id', 'scheduled_for', 'purpose',
        'badge_no', 'meet_at', 'items_carried', 'vehicle_no', 'status',
        'checked_in_at', 'checked_in_by', 'checked_out_at', 'checked_out_by',
        'registered_by', 'cancel_reason', 'notes',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function checkInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    /* ------------------------------------------------------------------ state */

    public function isExpected(): bool
    {
        return $this->status === self::STATUS_EXPECTED;
    }

    public function isInside(): bool
    {
        return $this->status === self::STATUS_INSIDE;
    }

    public function isOut(): bool
    {
        return $this->status === self::STATUS_OUT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    /** Nothing more will happen to this row. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_OUT, self::STATUS_CANCELLED, self::STATUS_NO_SHOW], true);
    }

    public function wasBooked(): bool
    {
        return $this->scheduled_for !== null;
    }

    /** The day the visit belongs to: the booking, or the day they walked in. */
    public function day(): ?Carbon
    {
        return $this->scheduled_for?->copy()->startOfDay()
            ?? $this->checked_in_at?->copy()->startOfDay()
            ?? $this->created_at?->copy()->startOfDay();
    }

    public function isToday(): bool
    {
        return $this->day()?->isToday() ?? false;
    }

    /** Minutes on the premises — null until both ends of the stay exist. */
    public function dwellMinutes(): ?int
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return null;
        }

        return max(0, (int) $this->checked_in_at->diffInMinutes($this->checked_out_at, false));
    }

    public function dwellLabel(): string
    {
        $minutes = $this->dwellMinutes();

        if ($minutes === null) {
            return '—';
        }

        return $minutes >= 60
            ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m'
            : $minutes.'m';
    }

    /** Booked for a day that has passed and never checked in. */
    public function isNoShowBy(): bool
    {
        return $this->isExpected()
            && $this->scheduled_for !== null
            && $this->scheduled_for->copy()->startOfDay()->isPast();
    }

    /** Somebody who came in and has not been seen leaving. */
    public function isOverstaying(int $hours = self::OVERSTAY_HOURS): bool
    {
        return $this->isInside()
            && $this->checked_in_at !== null
            && $this->checked_in_at->diffInHours(Carbon::now(), false) >= $hours;
    }

    public function stateLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EXPECTED => 'Expected',
            self::STATUS_INSIDE => 'Inside',
            self::STATUS_OUT => 'Checked out',
            self::STATUS_NO_SHOW => 'No show',
            default => 'Cancelled',
        };
    }

    public function purposeLabel(): string
    {
        return VisitorRegistry::PURPOSES[$this->purpose]['label'] ?? ucfirst((string) $this->purpose);
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOnDay(Builder $query, Carbon|string $day): Builder
    {
        $date = $day instanceof Carbon ? $day->toDateString() : $day;

        return $query->where(function (Builder $inner) use ($date) {
            $inner->whereDate('scheduled_for', $date)
                ->orWhere(function (Builder $walkIn) use ($date) {
                    $walkIn->whereNull('scheduled_for')->whereDate('created_at', $date);
                });
        });
    }
}

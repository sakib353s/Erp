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
 * §12-11 — a meeting, and the four things that can happen to it.
 *
 * Scheduled → held → minuted is the ordinary life of one; cancelled is the other
 * ending. Nothing here is a calendar client and nothing here pretends to be: it
 * is the office's record of who was asked, who came, what was said and what was
 * decided, sitting next to the tasks those decisions became.
 *
 * Two readings the rest of the module depends on:
 *
 *  · **is it over?** — a meeting is past when its end (or start, if no end was
 *    given) is behind us. That is a question about the clock, so it is answered
 *    from the clock, exactly as a licence's expiry is.
 *  · **does it need minutes?** — held, and no minutes on file. That is the list
 *    somebody has to work through, and it is why `minutes` is nullable rather than
 *    defaulting to a reassuring empty string.
 */
class Meeting extends Model
{
    use Auditable;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_HELD = 'held';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_HELD => 'Held',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** status => the statuses it may move to. Cancelled is final. */
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_HELD, self::STATUS_CANCELLED],
        self::STATUS_HELD => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    public const ROLES = [
        'chair' => 'Chairs it',
        'secretary' => 'Takes the minutes',
        'attendee' => 'Attends',
    ];

    public const RESPONSES = [
        'pending' => 'No answer yet',
        'accepted' => 'Coming',
        'declined' => 'Cannot come',
    ];

    public const ATTENDANCE = [
        'present' => 'Present',
        'absent' => 'Absent',
        'apology' => 'Sent apologies',
    ];

    /** How long a meeting is assumed to run when no end was given. */
    public const DEFAULT_MINUTES = 60;

    /**
     * How near a meeting has to be before the reminder goes out. The scheduled
     * command runs every fifteen minutes, so this is the window it looks inside.
     */
    public const REMINDER_MINUTES = 60;

    protected $fillable = [
        'company_id', 'branch_id', 'title', 'agenda', 'location', 'starts_at', 'ends_at',
        'status', 'held_at', 'cancelled_at', 'cancelled_reason', 'minutes',
        'minutes_recorded_at', 'minutes_recorded_by', 'chaired_by', 'scheduled_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'held_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'minutes_recorded_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function chair(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chaired_by');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function minuteTaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minutes_recorded_by');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MeetingEvent::class)->orderByDesc('happened_at')->orderByDesc('id');
    }

    /** The tasks this meeting produced — action items, carried as real work. */
    public function actionItems(): HasMany
    {
        return $this->hasMany(Task::class, 'meeting_id')->orderBy('id');
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeHeld($query)
    {
        return $query->where('status', self::STATUS_HELD);
    }

    /** Still to come: scheduled, and not yet started. */
    public function scopeUpcoming($query)
    {
        return $query->scheduled()->where('starts_at', '>=', now());
    }

    /** Invitations for a person: they are on the attendee list. */
    public function scopeForPerson($query, int $userId)
    {
        return $query->whereHas('attendees', fn ($q) => $q->where('user_id', $userId));
    }

    /* -------------------------------------------------------------- the clock */

    /** When it ends: the end time, or a default hour after it started. */
    public function endsAt(): CarbonInterface
    {
        return $this->ends_at ?? $this->starts_at->copy()->addMinutes(self::DEFAULT_MINUTES);
    }

    public function isPast(): bool
    {
        return $this->endsAt()->isPast();
    }

    public function isToday(): bool
    {
        return $this->starts_at->isSameDay(now());
    }

    /** Held, and nobody has written down what was said. */
    public function needsMinutes(): bool
    {
        return $this->status === self::STATUS_HELD && trim((string) $this->minutes) === '';
    }

    public function hasMinutes(): bool
    {
        return trim((string) $this->minutes) !== '';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** The people who were actually there, for the minutes line. */
    public function presentCount(): int
    {
        return $this->attendees->where('attendance', 'present')->count();
    }

    public function attendedCount(): int
    {
        return $this->attendees->whereIn('attendance', ['present', 'apology'])->count();
    }
}

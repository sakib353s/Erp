<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-11 — the meeting's own history: it was scheduled, moved, held, minuted,
 * cancelled; somebody answered; an action item came out of it.
 *
 * A reschedule is why this table exists. “Moved from Tuesday 10:00 to Wednesday
 * 15:00, because the auditor was only free on Wednesday” is the sentence that
 * explains why five people's Tuesday looks odd, and an `updated_at` cannot say it.
 */
class MeetingEvent extends Model
{
    public const ACTIONS = [
        'scheduled' => 'Scheduled',
        'rescheduled' => 'Moved',
        'held' => 'Held',
        'cancelled' => 'Cancelled',
        'responded' => 'Answered',
        'attendance' => 'Attendance marked',
        'minutes' => 'Minutes recorded',
        'action_item' => 'Action item raised',
        'invited' => 'Somebody added',
    ];

    protected $fillable = [
        'company_id', 'meeting_id', 'action', 'happened_at', 'note', 'meta', 'actor_id',
    ];

    protected $casts = [
        'happened_at' => 'datetime',
        'meta' => 'array',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst((string) $this->action);
    }
}

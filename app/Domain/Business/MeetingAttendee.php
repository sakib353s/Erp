<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-11 — one person on one meeting's list.
 *
 * Invited is not the same as coming, and coming is not the same as having been
 * there: `response` is what the person said beforehand, `attendance` is what the
 * room saw afterwards. Recording both is what lets an apology be an apology
 * rather than an unexplained absence.
 *
 * An outside person — the auditor, the landlord — has a `name` and no `user_id`,
 * because a meeting that cannot list the people who were in the room is not a
 * record of the meeting.
 */
class MeetingAttendee extends Model
{
    protected $fillable = [
        'company_id', 'meeting_id', 'user_id', 'name', 'role', 'response',
        'responded_at', 'attendance', 'invited_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'invited_at' => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What to call them on the page: their account name, or the name given. */
    public function displayName(): string
    {
        return $this->user?->name ?? ($this->name ?: 'Unnamed attendee');
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }

    public function roleLabel(): string
    {
        return Meeting::ROLES[$this->role] ?? ucfirst((string) $this->role);
    }

    public function responseLabel(): string
    {
        return Meeting::RESPONSES[$this->response] ?? ucfirst((string) $this->response);
    }

    public function attendanceLabel(): string
    {
        return $this->attendance === null
            ? 'Not marked'
            : (Meeting::ATTENDANCE[$this->attendance] ?? ucfirst((string) $this->attendance));
    }
}

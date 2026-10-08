<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-13 — what happened to a task, in order.
 *
 * The audit chain says *that* a task changed; this says what the workflow did —
 * who moved it from blocked back to in progress, and why. Append-only by
 * convention: nothing in the application updates or deletes these rows.
 */
class TaskEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const ASSIGNED = 'assigned';

    public const STATUS_CHANGED = 'status_changed';

    public const COMMENTED = 'commented';

    public const DUE_CHANGED = 'due_changed';

    protected $fillable = [
        'company_id', 'task_id', 'event', 'from_status', 'to_status', 'user_id', 'note', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
